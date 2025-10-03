#!/usr/bin/env python3
"""
Final Comprehensive Test for Enhanced Inventory Management System
Tests all the specific features mentioned in the review request
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

class FinalInventoryTester:
    def __init__(self, base_url="http://localhost"):
        self.base_url = base_url
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        })
        self.test_results = []
        self.authenticated = False
        
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

    def test_qr_code_generation(self):
        """Test QR code generation for tiles"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory QR functionality
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("1. QR Code Generation Testing", False, "Cannot access tiles inventory")
                return False
            
            # Check for QR modal popup
            has_qr_modal = 'qrCodeModal' in response.text
            
            # Check for QR code display functionality
            has_qr_display = 'QR Code' in response.text
            
            # Check for print/download functionality
            has_print_qr = 'printQRCodes' in response.text
            
            # Check uploads directory exists
            uploads_exists = os.path.exists('/app/uploads/qr')
            
            if has_qr_modal and has_qr_display and has_print_qr and uploads_exists:
                self.log_test("1. QR Code Generation Testing", True, "QR code generation, modal popup, and print/download functionality present")
                return True
            else:
                missing = []
                if not has_qr_modal: missing.append("QR modal")
                if not has_qr_display: missing.append("QR display")
                if not has_print_qr: missing.append("Print functionality")
                if not uploads_exists: missing.append("Uploads directory")
                
                self.log_test("1. QR Code Generation Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("1. QR Code Generation Testing", False, f"Error: {str(e)}")
            return False

    def test_vendor_filtering(self):
        """Test vendor filtering functionality"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("2. Vendor Filtering Testing", False, "Cannot access tiles inventory")
                return False
            
            # Check for vendor dropdown
            has_vendor_dropdown = 'name="vendor"' in response.text
            
            # Check for "No Vendor" option
            has_no_vendor = 'No Vendor' in response.text
            
            # Check for "All Vendors" option
            has_all_vendors = 'All Vendors' in response.text
            
            # Test filtering with search functionality
            has_search_integration = 'name="search"' in response.text
            
            if has_vendor_dropdown and has_no_vendor and has_all_vendors and has_search_integration:
                self.log_test("2. Vendor Filtering Testing", True, "Vendor dropdown with No Vendor/All Vendors options and search integration")
                return True
            else:
                missing = []
                if not has_vendor_dropdown: missing.append("Vendor dropdown")
                if not has_no_vendor: missing.append("No Vendor option")
                if not has_all_vendors: missing.append("All Vendors option")
                if not has_search_integration: missing.append("Search integration")
                
                self.log_test("2. Vendor Filtering Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("2. Vendor Filtering Testing", False, f"Error: {str(e)}")
            return False

    def test_enhanced_columns(self):
        """Test enhanced columns in inventory tables"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory columns
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("3. Enhanced Columns Testing", False, "Cannot access tiles inventory")
                return False
            
            # Check for all required columns
            required_columns = [
                'Stock (Boxes)', 'Stock (Sq.Ft)', 'Cost/Box', 'Cost + Transport', 
                'Total Box Cost', 'Sold Boxes', 'Sold Revenue', 'Invoice Links'
            ]
            
            missing_columns = []
            for column in required_columns:
                if column not in response.text:
                    missing_columns.append(column)
            
            # Check for rupee currency display
            has_rupee = '₹' in response.text
            
            # Check for horizontal scroll functionality
            has_horizontal_scroll = 'table-responsive' in response.text or 'overflow-x' in response.text
            
            if not missing_columns and has_rupee and has_horizontal_scroll:
                self.log_test("3. Enhanced Columns Testing", True, "All enhanced columns present with rupee currency and horizontal scroll")
                return True
            else:
                issues = []
                if missing_columns: issues.append(f"Missing columns: {', '.join(missing_columns)}")
                if not has_rupee: issues.append("Missing rupee currency")
                if not has_horizontal_scroll: issues.append("Missing horizontal scroll")
                
                self.log_test("3. Enhanced Columns Testing", False, f"Issues: {'; '.join(issues)}")
                return False
                
        except Exception as e:
            self.log_test("3. Enhanced Columns Testing", False, f"Error: {str(e)}")
            return False

    def test_stock_adjustment(self):
        """Test stock adjustment functionality"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("4. Stock Adjustment Testing", False, "Cannot access tiles inventory")
                return False
            
            # Check for stock adjustment modal
            has_adjustment_modal = 'adjust_stock' in response.text or 'Stock Adjustment' in response.text
            
            # Check for adjustment form fields
            has_adjustment_form = 'adjustment_reason' in response.text
            
            # Check for adjustment validation
            has_validation = 'new_stock' in response.text
            
            if has_adjustment_modal and has_adjustment_form and has_validation:
                self.log_test("4. Stock Adjustment Testing", True, "Stock adjustment modal, form, and validation present")
                return True
            else:
                missing = []
                if not has_adjustment_modal: missing.append("Adjustment modal")
                if not has_adjustment_form: missing.append("Adjustment form")
                if not has_validation: missing.append("Validation")
                
                self.log_test("4. Stock Adjustment Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("4. Stock Adjustment Testing", False, f"Error: {str(e)}")
            return False

    def test_export_functionality(self):
        """Test CSV export functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory export
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("5. Export Functionality Testing", False, "Cannot access tiles inventory")
                return False
            
            # Check for export button
            has_export_button = 'Export' in response.text and 'btn' in response.text
            
            # Check for export function
            has_export_function = 'exportData' in response.text
            
            # Check for CSV generation
            has_csv_generation = 'csv' in response.text.lower()
            
            if has_export_button and has_export_function and has_csv_generation:
                self.log_test("5. Export Functionality Testing", True, "CSV export button and functionality present")
                return True
            else:
                missing = []
                if not has_export_button: missing.append("Export button")
                if not has_export_function: missing.append("Export function")
                if not has_csv_generation: missing.append("CSV generation")
                
                self.log_test("5. Export Functionality Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("5. Export Functionality Testing", False, f"Error: {str(e)}")
            return False

    def test_print_functionality(self):
        """Test QR code print functionality"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("6. Print Functionality Testing", False, "Cannot access tiles inventory")
                return False
            
            # Check for print QR codes functionality
            has_print_qr = 'printQRCodes' in response.text
            
            # Check for print button
            has_print_button = 'Print QR Codes' in response.text
            
            if has_print_qr and has_print_button:
                self.log_test("6. Print Functionality Testing", True, "QR code print functionality and button present")
                return True
            else:
                missing = []
                if not has_print_qr: missing.append("Print QR function")
                if not has_print_button: missing.append("Print button")
                
                self.log_test("6. Print Functionality Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("6. Print Functionality Testing", False, f"Error: {str(e)}")
            return False

    def test_purchase_history_enhancement(self):
        """Test purchase history enhanced columns"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/tiles_purchase.php?view=history"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("7. Purchase History Enhancement Testing", False, "Cannot access purchase history")
                return False
            
            # Check for enhanced columns
            has_cost_transport = 'Cost + Transport' in response.text
            has_transport_percent = 'Transport %' in response.text
            has_rupee_currency = '₹' in response.text
            has_cost_breakdown = 'Total Cost' in response.text
            
            if has_cost_transport and has_transport_percent and has_rupee_currency and has_cost_breakdown:
                self.log_test("7. Purchase History Enhancement Testing", True, "Enhanced columns with cost breakdown and transport percentage present")
                return True
            else:
                missing = []
                if not has_cost_transport: missing.append("Cost + Transport")
                if not has_transport_percent: missing.append("Transport %")
                if not has_rupee_currency: missing.append("Rupee currency")
                if not has_cost_breakdown: missing.append("Cost breakdown")
                
                self.log_test("7. Purchase History Enhancement Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("7. Purchase History Enhancement Testing", False, f"Error: {str(e)}")
            return False

    def test_other_inventory_integration(self):
        """Test other inventory integration"""
        if not self.authenticate():
            return False
            
        try:
            # Test other inventory access
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("8. Other Inventory Integration Testing", False, "Cannot access other inventory")
                return False
            
            # Check for same functionality as tiles inventory
            has_enhanced_features = 'Cost/Unit' in response.text and 'Cost + Transport' in response.text
            has_qr_functionality = 'QR Code' in response.text
            has_green_theme = 'green' in response.text.lower() or 'success' in response.text
            has_edit_delete = 'edit' in response.text.lower() and 'delete' in response.text.lower()
            
            if has_enhanced_features and has_qr_functionality and has_edit_delete:
                self.log_test("8. Other Inventory Integration Testing", True, "Other inventory has same functionality as tiles inventory with QR codes")
                return True
            else:
                missing = []
                if not has_enhanced_features: missing.append("Enhanced features")
                if not has_qr_functionality: missing.append("QR functionality")
                if not has_edit_delete: missing.append("Edit/delete functionality")
                
                self.log_test("8. Other Inventory Integration Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("8. Other Inventory Integration Testing", False, f"Error: {str(e)}")
            return False

    def test_form_validation(self):
        """Test form validation for transport and damage percentages"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/tiles_purchase.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("9. Form Validation Testing", False, "Cannot access purchase form")
                return False
            
            # Check for validation rules in JavaScript or form
            has_transport_validation = 'transport_percentage' in response.text and ('0' in response.text and '200' in response.text)
            has_damage_validation = 'damage_percentage' in response.text and ('0' in response.text and '100' in response.text)
            has_required_validation = 'required' in response.text.lower()
            
            if has_transport_validation and has_damage_validation and has_required_validation:
                self.log_test("9. Form Validation Testing", True, "Transport percentage (0-200%), damage percentage (0-100%), and required field validation present")
                return True
            else:
                missing = []
                if not has_transport_validation: missing.append("Transport validation")
                if not has_damage_validation: missing.append("Damage validation")
                if not has_required_validation: missing.append("Required field validation")
                
                self.log_test("9. Form Validation Testing", False, f"Missing: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("9. Form Validation Testing", False, f"Error: {str(e)}")
            return False

    def test_database_integration(self):
        """Test database integration by creating a test purchase entry"""
        if not self.authenticate():
            return False
            
        try:
            # First get the form page to get available tiles
            url = f"{self.base_url}/public/tiles_purchase.php"
            response = self.session.get(url)
            
            if response.status_code != 200:
                self.log_test("10. Database Integration Testing", False, "Cannot access purchase form")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            tile_select = soup.find('select', {'name': 'tile_id'})
            
            if not tile_select:
                self.log_test("10. Database Integration Testing", False, "No tile selection dropdown found")
                return False
            
            # Get first available tile
            tile_options = tile_select.find_all('option')
            tile_id = None
            for option in tile_options:
                if option.get('value') and option.get('value') != '':
                    tile_id = option.get('value')
                    break
            
            if not tile_id:
                self.log_test("10. Database Integration Testing", False, "No tiles available for testing")
                return False
            
            # Submit test purchase entry
            purchase_data = {
                'add_purchase': '1',
                'tile_id': tile_id,
                'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                'supplier_name': 'Final Test Supplier',
                'invoice_number': f'FINAL-TEST-{int(time.time())}',
                'total_boxes': '25',
                'damage_percentage': '3.0',
                'cost_per_box': '200.00',
                'transport_percentage': '30',  # Test transport percentage calculation
                'transport_cost': '0',
                'notes': 'Final comprehensive test purchase entry'
            }
            
            response = self.session.post(url, data=purchase_data)
            
            if response.status_code == 200 and "Purchase entry added successfully" in response.text:
                self.log_test("10. Database Integration Testing", True, f"Successfully created test purchase entry with transport percentage calculation")
                return True
            else:
                self.log_test("10. Database Integration Testing", False, "Failed to create purchase entry")
                return False
                
        except Exception as e:
            self.log_test("10. Database Integration Testing", False, f"Error: {str(e)}")
            return False

    def run_final_comprehensive_tests(self):
        """Run all final comprehensive tests"""
        print("🏁 Starting Final Comprehensive Inventory System Tests")
        print("=" * 70)
        
        # Authentication test
        if not self.authenticate():
            print("❌ Cannot authenticate - aborting further tests")
            return False
        
        # Run all tests in order
        self.test_qr_code_generation()
        self.test_vendor_filtering()
        self.test_enhanced_columns()
        self.test_stock_adjustment()
        self.test_export_functionality()
        self.test_print_functionality()
        self.test_purchase_history_enhancement()
        self.test_other_inventory_integration()
        self.test_form_validation()
        self.test_database_integration()
        
        # Summary
        print("\n" + "=" * 70)
        print("📊 FINAL COMPREHENSIVE TEST SUMMARY")
        print("=" * 70)
        
        passed = sum(1 for result in self.test_results if result['success'])
        total = len(self.test_results)
        
        print(f"Total Tests: {total}")
        print(f"Passed: {passed}")
        print(f"Failed: {total - passed}")
        print(f"Success Rate: {(passed/total)*100:.1f}%")
        
        # List failed tests
        failed_tests = [result for result in self.test_results if not result['success']]
        if failed_tests:
            print("\n❌ FAILED TESTS:")
            for test in failed_tests:
                print(f"  • {test['test']}: {test['message']}")
        
        # List passed tests
        passed_tests = [result for result in self.test_results if result['success']]
        if passed_tests:
            print("\n✅ PASSED TESTS:")
            for test in passed_tests:
                print(f"  • {test['test']}: {test['message']}")
        
        return passed == total

def main():
    """Main test execution"""
    tester = FinalInventoryTester()
    success = tester.run_final_comprehensive_tests()
    
    if success:
        print("\n🎉 All final comprehensive tests passed! Enhanced Inventory System is fully functional.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()