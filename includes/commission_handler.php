<?php
// includes/commission_handler.php - Commission handling functionality

class CommissionHandler {
    private $pdo;
    
    public function __construct($pdo) {
        $this->pdo = $pdo;
    }
    
    public function applyCommission($quotation_id, $commission_user_id, $commission_percentage) {
        try {
            // Get current quotation total
            $total_stmt = $this->pdo->prepare("
                SELECT 
                    COALESCE(SUM(qi.line_total), 0) + COALESCE(SUM(qmi.line_total), 0) as subtotal
                FROM quotations q
                LEFT JOIN quotation_items qi ON q.id = qi.quotation_id
                LEFT JOIN quotation_misc_items qmi ON q.id = qmi.quotation_id
                WHERE q.id = ?
            ");
            $total_stmt->execute([$quotation_id]);
            $subtotal = (float)$total_stmt->fetchColumn();
            
            // Calculate commission amount
            $commission_amount = ($subtotal * $commission_percentage) / 100;
            
            // Update quotation with commission
            $stmt = $this->pdo->prepare("
                UPDATE quotations 
                SET commission_user_id = ?, commission_percentage = ?, commission_amount = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            
            if ($stmt->execute([$commission_user_id, $commission_percentage, $commission_amount, $quotation_id])) {
                // Create commission record
                $this->createCommissionRecord('quotation', $quotation_id, $commission_user_id, $subtotal, $commission_percentage, $commission_amount);
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("Commission error: " . $e->getMessage());
            return false;
        }
    }
    
    public function applyInvoiceCommission($invoice_id, $commission_user_id, $commission_percentage) {
        try {
            // Get current invoice total
            $total_stmt = $this->pdo->prepare("
                SELECT 
                    COALESCE(SUM(ii.line_total), 0) + COALESCE(SUM(imi.line_total), 0) as subtotal
                FROM invoices i
                LEFT JOIN invoice_items ii ON i.id = ii.invoice_id
                LEFT JOIN invoice_misc_items imi ON i.id = imi.invoice_id
                WHERE i.id = ?
            ");
            $total_stmt->execute([$invoice_id]);
            $subtotal = (float)$total_stmt->fetchColumn();
            
            // Calculate commission amount
            $commission_amount = ($subtotal * $commission_percentage) / 100;
            
            // Update invoice with commission
            $stmt = $this->pdo->prepare("
                UPDATE invoices 
                SET commission_user_id = ?, commission_percentage = ?, commission_amount = ?, updated_at = datetime('now')
                WHERE id = ?
            ");
            
            if ($stmt->execute([$commission_user_id, $commission_percentage, $commission_amount, $invoice_id])) {
                // Create commission record
                $this->createCommissionRecord('invoice', $invoice_id, $commission_user_id, $subtotal, $commission_percentage, $commission_amount);
                return true;
            }
            return false;
        } catch (Exception $e) {
            error_log("Invoice commission error: " . $e->getMessage());
            return false;
        }
    }
    
    private function createCommissionRecord($document_type, $document_id, $user_id, $base_amount, $commission_percentage, $commission_amount) {
        $stmt = $this->pdo->prepare("
            INSERT INTO commission_records 
            (document_type, document_id, user_id, base_amount, commission_percentage, commission_amount, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        return $stmt->execute([$document_type, $document_id, $user_id, $base_amount, $commission_percentage, $commission_amount]);
    }
    
    public function getCommissionRecords($user_id = null, $status = null, $date_from = null, $date_to = null) {
        $sql = "
            SELECT cr.*, 
                   CASE 
                       WHEN cr.document_type = 'quotation' THEN q.quote_no 
                       WHEN cr.document_type = 'invoice' THEN i.invoice_no 
                   END as document_no,
                   CASE 
                       WHEN cr.document_type = 'quotation' THEN q.customer_name 
                       WHEN cr.document_type = 'invoice' THEN i.customer_name 
                   END as customer_name,
                   u.username
            FROM commission_records cr
            LEFT JOIN quotations q ON cr.document_type = 'quotation' AND cr.document_id = q.id
            LEFT JOIN invoices i ON cr.document_type = 'invoice' AND cr.document_id = i.id
            LEFT JOIN users_simple u ON cr.user_id = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($user_id) {
            $sql .= " AND cr.user_id = ?";
            $params[] = $user_id;
        }
        
        if ($status) {
            $sql .= " AND cr.status = ?";
            $params[] = $status;
        }
        
        if ($date_from) {
            $sql .= " AND DATE(cr.created_at) >= ?";
            $params[] = $date_from;
        }
        
        if ($date_to) {
            $sql .= " AND DATE(cr.created_at) <= ?";
            $params[] = $date_to;
        }
        
        $sql .= " ORDER BY cr.created_at DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function updateCommissionStatus($commission_id, $status, $notes = '') {
        $stmt = $this->pdo->prepare("
            UPDATE commission_records 
            SET status = ?, notes = ?, 
                approved_at = CASE WHEN ? = 'approved' THEN datetime('now') ELSE approved_at END,
                paid_at = CASE WHEN ? = 'paid' THEN datetime('now') ELSE paid_at END
            WHERE id = ?
        ");
        return $stmt->execute([$status, $notes, $status, $status, $commission_id]);
    }
}
?>