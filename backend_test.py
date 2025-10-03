#!/usr/bin/env python3
"""
Backend Test Suite for PHP-based Invoice System with Discount Functionality
Tests the invoice system focusing on discount_amount field fixes, invoice creation,
quotation to invoice conversion, discount application, and invoice display.
"""

import requests
import json
import time
from urllib.parse import urljoin, urlparse, parse_qs
import re
from bs4 import BeautifulSoup
import sys
import os
from datetime import datetime

class InvoiceSystemTester:
    def __init__(self, base_url="https://tilecrm-app.preview.emergentagent.com"):
        self.base_url = base_url
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        })
        self.test_results = []
        self.authenticated = False
        self.test_quotation_id = None
        self.test_invoice_id = None
        
    def log_test(self, test_name, success, message="", details=""):
        """Log test results"""
        status = "✅ PASS" if success else "❌ FAIL"
        result = {
            'test': test_name,
            'status': status,
            'success': success,
            'message': message,
            'details': details
        }
        self.test_results.append(result)
        print(f"{status}: {test_name}")
        if message:
            print(f"    {message}")
        if details and not success:
            print(f"    Details: {details}")
        print()
        
    def authenticate(self):
        """Authenticate with admin credentials"""
        if self.authenticated:
            return True
            
        try:
            # Get login page first
            login_url = f"{self.base_url}/public/login_clean.php"
            response = self.session.get(login_url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Authentication Setup", False, f"Cannot access login page: HTTP {response.status_code}")
                return False
            
            # Submit login form
            login_data = {
                'username': 'admin',
                'password': 'admin123'
            }
            
            response = self.session.post(login_url, data=login_data, allow_redirects=True)
            
            if response.status_code == 200 and ('dashboard' in response.url.lower() or 'index.php' in response.url):
                self.authenticated = True
                self.log_test("Authentication Setup", True, "Successfully authenticated as admin")
                return True
            else:
                self.log_test("Authentication Setup", False, f"Login failed: {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Authentication Setup", False, f"Authentication error: {str(e)}")
            return False

    def test_database_schema_verification(self):
        """Test database schema to verify discount_amount and final_total fields exist"""
        if not self.authenticate():
            return False
            
        try:
            # Try to access invoice creation page to check if discount fields are present
            url = f"{self.base_url}/public/invoice_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for discount-related elements in the page
                has_discount_section = 'Apply Discount' in response.text
                has_discount_type = 'discount_type' in response.text
                has_discount_value = 'discount_value' in response.text
                has_discount_amount = 'discount_amount' in response.text or 'Discount Amount' in response.text
                has_final_total = 'final_total' in response.text or 'Final Total' in response.text
                
                if has_discount_section and has_discount_type and has_discount_value and has_discount_amount and has_final_total:
                    self.log_test("Database Schema Verification", True, "All discount fields (discount_amount, final_total) are present in invoice system")
                    return True
                else:
                    missing_features = []
                    if not has_discount_section: missing_features.append("Discount section")
                    if not has_discount_type: missing_features.append("Discount type field")
                    if not has_discount_value: missing_features.append("Discount value field")
                    if not has_discount_amount: missing_features.append("Discount amount field")
                    if not has_final_total: missing_features.append("Final total field")
                    
                    self.log_test("Database Schema Verification", False, f"Missing discount fields: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Database Schema Verification", False, f"Cannot access invoice page: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Database Schema Verification", False, f"Error: {str(e)}")
            return False

    def test_invoice_creation(self):
        """Test creating a new invoice"""
        if not self.authenticate():
            return False
            
        try:
            # First get the invoice creation form
            url = f"{self.base_url}/public/invoice_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Invoice Creation", False, f"Cannot access invoice creation page: HTTP {response.status_code}")
                return False
            
            # Submit invoice creation form with realistic data
            invoice_data = {
                'create_invoice': '1',
                'invoice_dt': datetime.now().strftime('%Y-%m-%d'),
                'customer_name': 'Rajesh Kumar',
                'firm_name': 'Kumar Tiles & Ceramics',
                'phone': '9876543210',
                'customer_gst': '27ABCDE1234F1Z5',
                'notes': 'Test invoice creation for discount functionality testing'
            }
            
            response = self.session.post(url, data=invoice_data, allow_redirects=True)
            
            if response.status_code == 200:
                # Check if we were redirected to an invoice edit page (successful creation)
                if 'invoice_enhanced.php?id=' in response.url:
                    # Extract invoice ID from URL
                    import re
                    match = re.search(r'id=(\d+)', response.url)
                    if match:
                        self.test_invoice_id = int(match.group(1))
                        self.log_test("Invoice Creation", True, f"Successfully created invoice with ID: {self.test_invoice_id}")
                        return True
                    else:
                        self.log_test("Invoice Creation", False, "Invoice created but could not extract ID")
                        return False
                elif "Invoice" in response.text and "created successfully" in response.text:
                    self.log_test("Invoice Creation", True, "Invoice created successfully")
                    return True
                elif "required" in response.text.lower():
                    self.log_test("Invoice Creation", False, "Form validation error - missing required fields")
                    return False
                else:
                    self.log_test("Invoice Creation", False, "No success confirmation found", response.text[:500])
                    return False
            else:
                self.log_test("Invoice Creation", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Invoice Creation", False, f"Error: {str(e)}")
            return False

    def test_quotation_creation(self):
        """Test creating a quotation for later conversion to invoice"""
        if not self.authenticate():
            return False
            
        try:
            # First get the quotation creation form
            url = f"{self.base_url}/public/quotation_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Quotation Creation", False, f"Cannot access quotation creation page: HTTP {response.status_code}")
                return False
            
            # Submit quotation creation form with realistic data
            quotation_data = {
                'create_quote': '1',
                'quote_dt': datetime.now().strftime('%Y-%m-%d'),
                'customer_name': 'Priya Sharma',
                'firm_name': 'Sharma Construction',
                'phone': '9123456789',
                'customer_gst': '29XYZAB5678C1D2',
                'notes': 'Test quotation for invoice conversion testing'
            }
            
            response = self.session.post(url, data=quotation_data, allow_redirects=True)
            
            if response.status_code == 200:
                # Check if we were redirected to a quotation edit page (successful creation)
                if 'quotation_enhanced.php?id=' in response.url:
                    # Extract quotation ID from URL
                    import re
                    match = re.search(r'id=(\d+)', response.url)
                    if match:
                        self.test_quotation_id = int(match.group(1))
                        self.log_test("Quotation Creation", True, f"Successfully created quotation with ID: {self.test_quotation_id}")
                        return True
                    else:
                        self.log_test("Quotation Creation", False, "Quotation created but could not extract ID")
                        return False
                elif "Quotation" in response.text and "created successfully" in response.text:
                    self.log_test("Quotation Creation", True, "Quotation created successfully")
                    return True
                elif "required" in response.text.lower():
                    self.log_test("Quotation Creation", False, "Form validation error - missing required fields")
                    return False
                else:
                    self.log_test("Quotation Creation", False, "No success confirmation found", response.text[:500])
                    return False
            else:
                self.log_test("Quotation Creation", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation Creation", False, f"Error: {str(e)}")
            return False

    def test_quotation_to_invoice_conversion(self):
        """Test converting quotation to invoice"""
        if not self.authenticate():
            return False
            
        # First ensure we have a quotation to convert
        if not self.test_quotation_id:
            if not self.test_quotation_creation():
                self.log_test("Quotation to Invoice Conversion", False, "Cannot create test quotation for conversion")
                return False
            
        try:
            # Test conversion by accessing invoice creation with from_quote parameter
            url = f"{self.base_url}/public/invoice_enhanced.php?from_quote={self.test_quotation_id}"
            response = self.session.get(url, timeout=10, allow_redirects=True)
            
            if response.status_code == 200:
                # Check if conversion was successful
                if 'Invoice' in response.text and ('created successfully' in response.text or 'invoice_enhanced.php?id=' in response.url):
                    # Try to extract invoice ID if redirected
                    if 'invoice_enhanced.php?id=' in response.url:
                        import re
                        match = re.search(r'id=(\d+)', response.url)
                        if match:
                            converted_invoice_id = int(match.group(1))
                            self.test_invoice_id = converted_invoice_id
                            self.log_test("Quotation to Invoice Conversion", True, f"Successfully converted quotation {self.test_quotation_id} to invoice {converted_invoice_id}")
                            return True
                    
                    self.log_test("Quotation to Invoice Conversion", True, f"Successfully converted quotation {self.test_quotation_id} to invoice")
                    return True
                elif 'Quotation not found' in response.text:
                    self.log_test("Quotation to Invoice Conversion", False, f"Quotation {self.test_quotation_id} not found")
                    return False
                elif 'undefined array key' in response.text.lower() or 'discount_amount' in response.text:
                    self.log_test("Quotation to Invoice Conversion", False, "Undefined array key error detected during conversion")
                    return False
                else:
                    self.log_test("Quotation to Invoice Conversion", False, "Conversion failed - no success message", response.text[:500])
                    return False
            else:
                self.log_test("Quotation to Invoice Conversion", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation to Invoice Conversion", False, f"Error: {str(e)}")
            return False

    def test_discount_application(self):
        """Test applying discounts to invoices"""
        if not self.authenticate():
            return False
            
        # Ensure we have an invoice to test with
        if not self.test_invoice_id:
            if not self.test_invoice_creation():
                self.log_test("Discount Application", False, "Cannot create test invoice for discount testing")
                return False
        
        try:
            # Test percentage discount application
            url = f"{self.base_url}/public/invoice_enhanced.php?id={self.test_invoice_id}"
            
            # First get the invoice page to see current state
            response = self.session.get(url, timeout=10)
            if response.status_code != 200:
                self.log_test("Discount Application", False, f"Cannot access invoice {self.test_invoice_id}: HTTP {response.status_code}")
                return False
            
            # Check if discount section is present
            if 'Apply Discount' not in response.text:
                self.log_test("Discount Application", False, "Discount section not found on invoice page")
                return False
            
            # Apply a 10% discount
            discount_data = {
                'apply_discount': '1',
                'discount_type': 'percentage',
                'discount_value': '10'
            }
            
            response = self.session.post(url, data=discount_data, allow_redirects=True)
            
            if response.status_code == 200:
                if 'Discount applied successfully' in response.text:
                    self.log_test("Discount Application (Percentage)", True, "Successfully applied 10% discount")
                    
                    # Test fixed amount discount
                    discount_data = {
                        'apply_discount': '1',
                        'discount_type': 'fixed',
                        'discount_value': '500'
                    }
                    
                    response = self.session.post(url, data=discount_data, allow_redirects=True)
                    
                    if response.status_code == 200:
                        if 'Discount applied successfully' in response.text:
                            self.log_test("Discount Application (Fixed)", True, "Successfully applied ₹500 fixed discount")
                            return True
                        elif 'undefined array key' in response.text.lower() and 'discount_amount' in response.text.lower():
                            self.log_test("Discount Application (Fixed)", False, "Undefined array key 'discount_amount' error detected")
                            return False
                        else:
                            self.log_test("Discount Application (Fixed)", False, "Fixed discount application failed")
                            return False
                    else:
                        self.log_test("Discount Application (Fixed)", False, f"HTTP {response.status_code}")
                        return False
                        
                elif 'undefined array key' in response.text.lower() and 'discount_amount' in response.text.lower():
                    self.log_test("Discount Application (Percentage)", False, "Undefined array key 'discount_amount' error detected")
                    return False
                else:
                    self.log_test("Discount Application (Percentage)", False, "Percentage discount application failed")
                    return False
            else:
                self.log_test("Discount Application (Percentage)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Discount Application", False, f"Error: {str(e)}")
            return False

    def test_invoice_display_totals(self):
        """Test invoice display to verify totals are calculated correctly without errors"""
        if not self.authenticate():
            return False
            
        # Ensure we have an invoice to test with
        if not self.test_invoice_id:
            if not self.test_invoice_creation():
                self.log_test("Invoice Display Totals", False, "Cannot create test invoice for display testing")
                return False
        
        try:
            # Access the invoice page
            url = f"{self.base_url}/public/invoice_enhanced.php?id={self.test_invoice_id}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                # Check for undefined array key errors
                if 'undefined array key' in response.text.lower():
                    if 'discount_amount' in response.text.lower():
                        self.log_test("Invoice Display Totals", False, "Undefined array key 'discount_amount' error found in invoice display")
                        return False
                    elif 'final_total' in response.text.lower():
                        self.log_test("Invoice Display Totals", False, "Undefined array key 'final_total' error found in invoice display")
                        return False
                    else:
                        self.log_test("Invoice Display Totals", False, "Undefined array key error found in invoice display")
                        return False
                
                # Check for proper total display elements
                has_subtotal = 'Subtotal:' in response.text or 'subtotal' in response.text.lower()
                has_final_total = 'Final Total:' in response.text or 'final_total' in response.text.lower()
                has_currency = '₹' in response.text
                has_invoice_summary = 'Invoice Summary' in response.text or 'summary' in response.text.lower()
                
                # Check for discount display elements
                has_discount_section = 'Apply Discount' in response.text
                has_discount_amount_field = 'Discount Amount' in response.text
                
                if has_subtotal and has_final_total and has_currency and has_invoice_summary and has_discount_section and has_discount_amount_field:
                    self.log_test("Invoice Display Totals", True, "Invoice displays correctly with all total fields and no undefined array key errors")
                    return True
                else:
                    missing_elements = []
                    if not has_subtotal: missing_elements.append("Subtotal")
                    if not has_final_total: missing_elements.append("Final Total")
                    if not has_currency: missing_elements.append("Currency (₹)")
                    if not has_invoice_summary: missing_elements.append("Invoice Summary")
                    if not has_discount_section: missing_elements.append("Discount Section")
                    if not has_discount_amount_field: missing_elements.append("Discount Amount Field")
                    
                    self.log_test("Invoice Display Totals", False, f"Missing display elements: {', '.join(missing_elements)}")
                    return False
            else:
                self.log_test("Invoice Display Totals", False, f"Cannot access invoice: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Invoice Display Totals", False, f"Error: {str(e)}")
            return False

    def test_purchase_history_access(self):
        """Test access to purchase history with enhanced columns"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles purchase history
            url = f"{self.base_url}/public/tiles_purchase.php?view=history"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_cost_breakdown = 'Cost + Transport' in response.text
                has_transport_column = 'Transport %' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_breakdown and has_transport_column and has_rupee_currency:
                    self.log_test("Purchase History Access (Tiles)", True, "Enhanced history columns present")
                else:
                    self.log_test("Purchase History Access (Tiles)", False, "Missing enhanced history features")
                    return False
            else:
                self.log_test("Purchase History Access (Tiles)", False, f"HTTP {response.status_code}")
                return False
            
            # Test other items purchase history
            url = f"{self.base_url}/public/other_purchase.php?view=history"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_cost_breakdown = 'Cost + Transport' in response.text
                has_transport_column = 'Transport %' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_breakdown and has_transport_column and has_rupee_currency:
                    self.log_test("Purchase History Access (Other Items)", True, "Enhanced history columns present")
                    return True
                else:
                    self.log_test("Purchase History Access (Other Items)", False, "Missing enhanced history features")
                    return False
            else:
                self.log_test("Purchase History Access (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Purchase History Access", False, f"Error: {str(e)}")
            return False

    def test_qr_code_generation(self):
        """Test QR code generation functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles QR generation
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url)
            
            if response.status_code != 200:
                self.log_test("QR Code Generation", False, "Cannot access tiles inventory")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Look for QR generation buttons or existing QR codes
            qr_buttons = soup.find_all('button', string=lambda text: text and 'qr' in text.lower())
            qr_images = soup.find_all('img', class_='qr-thumb')
            
            has_qr_functionality = len(qr_buttons) > 0 or len(qr_images) > 0
            has_qr_modal = soup.find('div', id='qrCodeModal') is not None
            
            if has_qr_functionality and has_qr_modal:
                self.log_test("QR Code Generation (Tiles)", True, f"QR functionality present - {len(qr_buttons)} buttons, {len(qr_images)} existing QR codes")
            else:
                self.log_test("QR Code Generation (Tiles)", False, "QR functionality missing")
                return False
            
            # Test other items QR generation
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                qr_buttons = soup.find_all('button', string=lambda text: text and 'qr' in text.lower())
                qr_images = soup.find_all('img', class_='qr-thumb')
                has_qr_functionality = len(qr_buttons) > 0 or len(qr_images) > 0
                has_qr_modal = soup.find('div', id='qrCodeModal') is not None
                
                if has_qr_functionality and has_qr_modal:
                    self.log_test("QR Code Generation (Other Items)", True, f"QR functionality present - {len(qr_buttons)} buttons, {len(qr_images)} existing QR codes")
                    return True
                else:
                    self.log_test("QR Code Generation (Other Items)", False, "QR functionality missing")
                    return False
            else:
                self.log_test("QR Code Generation (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("QR Code Generation", False, f"Error: {str(e)}")
            return False

    def test_cost_calculations(self):
        """Test cost calculation features in inventory displays"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory cost calculations
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                # Check for cost calculation columns
                has_cost_per_box = 'Cost/Box' in response.text
                has_cost_with_transport = 'Cost + Transport' in response.text
                has_total_box_cost = 'Total Box Cost' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_per_box and has_cost_with_transport and has_total_box_cost and has_rupee_currency:
                    self.log_test("Cost Calculations (Tiles)", True, "All cost calculation columns present")
                else:
                    missing = []
                    if not has_cost_per_box: missing.append("Cost/Box")
                    if not has_cost_with_transport: missing.append("Cost + Transport")
                    if not has_total_box_cost: missing.append("Total Box Cost")
                    if not has_rupee_currency: missing.append("Rupee currency")
                    
                    self.log_test("Cost Calculations (Tiles)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Cost Calculations (Tiles)", False, f"HTTP {response.status_code}")
                return False
            
            # Test other items inventory cost calculations
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_cost_per_unit = 'Cost/Unit' in response.text
                has_cost_with_transport = 'Cost + Transport' in response.text
                has_total_cost = 'Total Cost' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_per_unit and has_cost_with_transport and has_total_cost and has_rupee_currency:
                    self.log_test("Cost Calculations (Other Items)", True, "All cost calculation columns present")
                    return True
                else:
                    missing = []
                    if not has_cost_per_unit: missing.append("Cost/Unit")
                    if not has_cost_with_transport: missing.append("Cost + Transport")
                    if not has_total_cost: missing.append("Total Cost")
                    if not has_rupee_currency: missing.append("Rupee currency")
                    
                    self.log_test("Cost Calculations (Other Items)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Cost Calculations (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Cost Calculations", False, f"Error: {str(e)}")
            return False

    def test_sales_data_integration(self):
        """Test sales data integration in inventory displays"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory sales data
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_sold_boxes = 'Sold Boxes' in response.text
                has_sold_revenue = 'Sold Revenue' in response.text
                has_invoice_links = 'Invoice Links' in response.text
                
                if has_sold_boxes and has_sold_revenue and has_invoice_links:
                    self.log_test("Sales Data Integration (Tiles)", True, "All sales data columns present")
                else:
                    missing = []
                    if not has_sold_boxes: missing.append("Sold Boxes")
                    if not has_sold_revenue: missing.append("Sold Revenue")
                    if not has_invoice_links: missing.append("Invoice Links")
                    
                    self.log_test("Sales Data Integration (Tiles)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Sales Data Integration (Tiles)", False, f"HTTP {response.status_code}")
                return False
            
            # Test other items inventory sales data
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_sold_quantity = 'Sold Quantity' in response.text
                has_sold_revenue = 'Sold Revenue' in response.text
                has_quote_links = 'Quote Links' in response.text
                
                if has_sold_quantity and has_sold_revenue and has_quote_links:
                    self.log_test("Sales Data Integration (Other Items)", True, "All sales data columns present")
                    return True
                else:
                    missing = []
                    if not has_sold_quantity: missing.append("Sold Quantity")
                    if not has_sold_revenue: missing.append("Sold Revenue")
                    if not has_quote_links: missing.append("Quote Links")
                    
                    self.log_test("Sales Data Integration (Other Items)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Sales Data Integration (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Sales Data Integration", False, f"Error: {str(e)}")
            return False

    def test_form_validation(self):
        """Test form validation for purchase entries"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles purchase validation
            url = f"{self.base_url}/public/tiles_purchase.php"
            
            # Test with invalid transport percentage (over 200%)
            invalid_data = {
                'add_purchase': '1',
                'tile_id': '1',
                'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                'total_boxes': '10',
                'damage_percentage': '5',
                'cost_per_box': '100',
                'transport_percentage': '250',  # Invalid - over 200%
            }
            
            response = self.session.post(url, data=invalid_data)
            
            if response.status_code == 200:
                if "Transport percentage must be between 0 and 200" in response.text:
                    self.log_test("Form Validation (Transport %)", True, "Correctly validates transport percentage limits")
                else:
                    self.log_test("Form Validation (Transport %)", False, "Should reject transport percentage > 200%")
                    return False
            else:
                self.log_test("Form Validation (Transport %)", False, f"HTTP {response.status_code}")
                return False
            
            # Test with invalid damage percentage (over 100%)
            invalid_data['transport_percentage'] = '30'
            invalid_data['damage_percentage'] = '150'  # Invalid - over 100%
            
            response = self.session.post(url, data=invalid_data)
            
            if response.status_code == 200:
                if "Damage percentage must be between 0 and 100" in response.text:
                    self.log_test("Form Validation (Damage %)", True, "Correctly validates damage percentage limits")
                    return True
                else:
                    self.log_test("Form Validation (Damage %)", False, "Should reject damage percentage > 100%")
                    return False
            else:
                self.log_test("Form Validation (Damage %)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Form Validation", False, f"Error: {str(e)}")
            return False

    def test_error_detection(self):
        """Test for specific undefined array key errors that were reported"""
        if not self.authenticate():
            return False
            
        try:
            # Test various invoice-related pages for undefined array key errors
            test_urls = [
                f"{self.base_url}/public/invoice_enhanced.php",
                f"{self.base_url}/public/quotation_enhanced.php",
            ]
            
            if self.test_invoice_id:
                test_urls.append(f"{self.base_url}/public/invoice_enhanced.php?id={self.test_invoice_id}")
            
            if self.test_quotation_id:
                test_urls.append(f"{self.base_url}/public/quotation_enhanced.php?id={self.test_quotation_id}")
            
            errors_found = []
            
            for url in test_urls:
                try:
                    response = self.session.get(url, timeout=10)
                    if response.status_code == 200:
                        # Check for undefined array key errors
                        if 'undefined array key' in response.text.lower():
                            if 'discount_amount' in response.text.lower():
                                errors_found.append(f"Undefined array key 'discount_amount' in {url}")
                            elif 'final_total' in response.text.lower():
                                errors_found.append(f"Undefined array key 'final_total' in {url}")
                            else:
                                errors_found.append(f"Undefined array key error in {url}")
                except Exception as e:
                    errors_found.append(f"Error accessing {url}: {str(e)}")
            
            if errors_found:
                self.log_test("Error Detection", False, f"Found {len(errors_found)} undefined array key errors", "; ".join(errors_found))
                return False
            else:
                self.log_test("Error Detection", True, "No undefined array key errors found in invoice system")
                return True
                
        except Exception as e:
            self.log_test("Error Detection", False, f"Error during error detection: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all invoice system tests focusing on discount functionality"""
        print("🧪 Starting Invoice System Tests - Discount Functionality Focus")
        print("=" * 70)
        
        # Authentication test
        if not self.authenticate():
            print("❌ Cannot authenticate - aborting further tests")
            return False
        
        # Core invoice system tests
        print("\n📋 Testing Database Schema and Core Functionality...")
        self.test_database_schema_verification()
        
        print("\n📝 Testing Invoice Creation...")
        self.test_invoice_creation()
        
        print("\n📄 Testing Quotation Creation...")
        self.test_quotation_creation()
        
        print("\n🔄 Testing Quotation to Invoice Conversion...")
        self.test_quotation_to_invoice_conversion()
        
        print("\n💰 Testing Discount Application...")
        self.test_discount_application()
        
        print("\n📊 Testing Invoice Display and Totals...")
        self.test_invoice_display_totals()
        
        print("\n🔍 Testing for Undefined Array Key Errors...")
        self.test_error_detection()
        
        # Summary
        print("\n" + "=" * 70)
        print("📊 INVOICE SYSTEM TEST SUMMARY")
        print("=" * 70)
        
        passed = sum(1 for result in self.test_results if result['success'])
        total = len(self.test_results)
        
        print(f"Total Tests: {total}")
        print(f"Passed: {passed}")
        print(f"Failed: {total - passed}")
        print(f"Success Rate: {(passed/total)*100:.1f}%")
        
        # List failed tests with details
        failed_tests = [result for result in self.test_results if not result['success']]
        if failed_tests:
            print("\n❌ FAILED TESTS:")
            for test in failed_tests:
                print(f"  • {test['test']}: {test['message']}")
                if test['details']:
                    print(f"    Details: {test['details'][:200]}...")
        
        # List passed tests
        passed_tests = [result for result in self.test_results if result['success']]
        if passed_tests:
            print("\n✅ PASSED TESTS:")
            for test in passed_tests:
                print(f"  • {test['test']}: {test['message']}")
        
        return passed == total

def main():
    """Main test execution"""
    tester = InvoiceSystemTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! Invoice System with discount functionality is working correctly.")
        print("✅ No 'undefined array key discount_amount' errors found.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        print("🔍 Check for 'undefined array key' errors in the failed tests.")
        sys.exit(1)

if __name__ == "__main__":
    main()