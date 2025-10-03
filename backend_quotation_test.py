#!/usr/bin/env python3
"""
Backend Test Suite for Enhanced Quotation and Invoice System
Tests the complete quotation system including enhanced features, calculation modes,
stock availability, image display, form validation, and integration testing.
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

class EnhancedQuotationTester:
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

    def test_enhanced_quotation_access(self):
        """Test access to enhanced quotation creation page"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/quotation_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for enhanced quotation features
                has_customer_name = soup.find('input', {'name': 'customer_name'}) is not None
                has_firm_name = soup.find('input', {'name': 'firm_name'}) is not None
                has_mobile_field = soup.find('input', {'name': 'phone'}) is not None
                has_gst_field = soup.find('input', {'name': 'customer_gst'}) is not None
                has_user_preferences = 'show_item_images' in response.text
                
                if has_customer_name and has_firm_name and has_mobile_field and has_gst_field and has_user_preferences:
                    self.log_test("Enhanced Quotation Access", True, "All enhanced quotation features present")
                    return True
                else:
                    missing_features = []
                    if not has_customer_name: missing_features.append("Customer name field")
                    if not has_firm_name: missing_features.append("Firm name field")
                    if not has_mobile_field: missing_features.append("Mobile field")
                    if not has_gst_field: missing_features.append("GST field")
                    if not has_user_preferences: missing_features.append("User preferences")
                    
                    self.log_test("Enhanced Quotation Access", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Enhanced Quotation Access", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Enhanced Quotation Access", False, f"Error: {str(e)}")
            return False

    def test_quotation_creation_validation(self):
        """Test quotation creation with form validation"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/quotation_enhanced.php"
            
            # Test with missing required fields
            invalid_data = {
                'create_quote': '1',
                'quote_dt': datetime.now().strftime('%Y-%m-%d'),
                'customer_name': '',  # Missing required field
                'phone': '9876543210',
                'firm_name': 'Test Firm',
                'customer_gst': '',
                'notes': 'Test quotation'
            }
            
            response = self.session.post(url, data=invalid_data)
            
            if response.status_code == 200:
                if "Customer name is required" in response.text:
                    self.log_test("Customer Name Validation", True, "Correctly validates missing customer name")
                else:
                    self.log_test("Customer Name Validation", False, "Should reject empty customer name")
                    return False
            else:
                self.log_test("Customer Name Validation", False, f"HTTP {response.status_code}")
                return False
            
            # Test with invalid mobile number
            invalid_data['customer_name'] = 'Rajesh Kumar'
            invalid_data['phone'] = '123'  # Invalid mobile number
            
            response = self.session.post(url, data=invalid_data)
            
            if response.status_code == 200:
                if "Mobile number must be 10 digits" in response.text:
                    self.log_test("Mobile Number Validation", True, "Correctly validates mobile number format")
                    return True
                else:
                    self.log_test("Mobile Number Validation", False, "Should reject invalid mobile number format")
                    return False
            else:
                self.log_test("Mobile Number Validation", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation Creation Validation", False, f"Error: {str(e)}")
            return False

    def test_quotation_creation_success(self):
        """Test successful quotation creation with all enhanced fields"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/quotation_enhanced.php"
            
            # Create quotation with all enhanced fields
            quotation_data = {
                'create_quote': '1',
                'quote_dt': datetime.now().strftime('%Y-%m-%d'),
                'customer_name': 'Priya Sharma',
                'firm_name': 'Sharma Constructions Pvt Ltd',
                'phone': '9876543210',
                'customer_gst': '27ABCDE1234F1Z5',
                'notes': 'Test quotation with enhanced fields - bathroom renovation project'
            }
            
            response = self.session.post(url, data=quotation_data, allow_redirects=False)
            
            if response.status_code == 302:  # Redirect after successful creation
                redirect_url = response.headers.get('Location', '')
                if 'quotation_enhanced.php?id=' in redirect_url:
                    # Extract quotation ID from redirect URL
                    quotation_id = redirect_url.split('id=')[1].split('&')[0]
                    self.created_quotation_id = int(quotation_id)
                    self.log_test("Enhanced Quotation Creation", True, f"Successfully created quotation ID: {quotation_id}")
                    return True
                else:
                    self.log_test("Enhanced Quotation Creation", False, f"Unexpected redirect: {redirect_url}")
                    return False
            else:
                self.log_test("Enhanced Quotation Creation", False, f"Expected redirect, got HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Enhanced Quotation Creation", False, f"Error: {str(e)}")
            return False

    def test_calculation_mode_toggle(self):
        """Test calculation mode toggle functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Access quotation edit page (assuming we have a quotation ID from previous test)
            quotation_id = getattr(self, 'created_quotation_id', 1)
            url = f"{self.base_url}/public/quotation_enhanced.php?id={quotation_id}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for calculation mode toggle
                has_sqft_mode = soup.find('input', {'value': 'sqft_mode'}) is not None
                has_direct_mode = soup.find('input', {'value': 'direct_mode'}) is not None
                has_calculation_toggle = 'Calculate by Area' in response.text and 'Direct Box Entry' in response.text
                has_sqft_fields = soup.find('input', {'name': 'length_ft'}) is not None
                has_direct_fields = soup.find('input', {'name': 'direct_boxes'}) is not None
                
                if has_sqft_mode and has_direct_mode and has_calculation_toggle and has_sqft_fields and has_direct_fields:
                    self.log_test("Calculation Mode Toggle", True, "Both calculation modes available with proper fields")
                    return True
                else:
                    missing_features = []
                    if not has_sqft_mode: missing_features.append("Sqft mode radio")
                    if not has_direct_mode: missing_features.append("Direct mode radio")
                    if not has_calculation_toggle: missing_features.append("Mode descriptions")
                    if not has_sqft_fields: missing_features.append("Sqft calculation fields")
                    if not has_direct_fields: missing_features.append("Direct box fields")
                    
                    self.log_test("Calculation Mode Toggle", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Calculation Mode Toggle", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Calculation Mode Toggle", False, f"Error: {str(e)}")
            return False

    def test_stock_availability_display(self):
        """Test stock availability display in item selection"""
        if not self.authenticate():
            return False
            
        try:
            quotation_id = getattr(self, 'created_quotation_id', 1)
            url = f"{self.base_url}/public/quotation_enhanced.php?id={quotation_id}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for stock information in tile dropdown
                tile_select = soup.find('select', {'name': 'tile_id'})
                misc_select = soup.find('select', {'name': 'misc_item_id'})
                
                has_tile_stock = False
                has_misc_stock = False
                
                if tile_select:
                    tile_options = tile_select.find_all('option')
                    for option in tile_options:
                        if 'Stock:' in option.text:
                            has_tile_stock = True
                            break
                
                if misc_select:
                    misc_options = misc_select.find_all('option')
                    for option in misc_options:
                        if 'Stock:' in option.text:
                            has_misc_stock = True
                            break
                
                has_stock_info_divs = soup.find('div', {'id': 'tileStockInfo'}) is not None and soup.find('div', {'id': 'miscStockInfo'}) is not None
                has_stock_warnings = 'stock-warning' in response.text or 'stock-available' in response.text
                
                if has_tile_stock and has_misc_stock and has_stock_info_divs:
                    self.log_test("Stock Availability Display", True, "Stock information displayed for both tiles and misc items")
                    return True
                else:
                    missing_features = []
                    if not has_tile_stock: missing_features.append("Tile stock display")
                    if not has_misc_stock: missing_features.append("Misc item stock display")
                    if not has_stock_info_divs: missing_features.append("Stock info containers")
                    
                    self.log_test("Stock Availability Display", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Stock Availability Display", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Stock Availability Display", False, f"Error: {str(e)}")
            return False

    def test_image_display_preferences(self):
        """Test user preference for image display"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/quotation_enhanced.php"
            
            # Test updating image display preference
            preference_data = {
                'update_preferences': '1',
                'show_item_images': 'on'  # Enable image display
            }
            
            response = self.session.post(url, data=preference_data)
            
            if response.status_code == 200:
                if "Preferences updated successfully" in response.text:
                    # Check if preference is reflected in the form
                    soup = BeautifulSoup(response.text, 'html.parser')
                    show_images_checkbox = soup.find('input', {'name': 'show_item_images'})
                    
                    if show_images_checkbox and show_images_checkbox.get('checked') is not None:
                        self.log_test("Image Display Preferences", True, "User preferences updated and reflected correctly")
                        return True
                    else:
                        self.log_test("Image Display Preferences", False, "Preference not reflected in UI")
                        return False
                else:
                    self.log_test("Image Display Preferences", False, "No success message for preference update")
                    return False
            else:
                self.log_test("Image Display Preferences", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Image Display Preferences", False, f"Error: {str(e)}")
            return False

    def test_enhanced_quotation_list_access(self):
        """Test access to enhanced quotation list with search and filtering"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/quotation_list_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for enhanced list features
                has_search_section = soup.find('div', class_='search-section') is not None
                has_single_date_picker = soup.find('input', {'name': 'single_date'}) is not None
                has_date_range_pickers = soup.find('input', {'name': 'date_from'}) is not None and soup.find('input', {'name': 'date_to'}) is not None
                has_customer_search = soup.find('input', {'name': 'search_customer'}) is not None
                has_firm_search = soup.find('input', {'name': 'search_firm'}) is not None
                has_gst_search = soup.find('input', {'name': 'search_gst'}) is not None
                has_statistics_cards = 'Total Quotations' in response.text and 'Total Value' in response.text and 'Average Value' in response.text
                
                if has_search_section and has_single_date_picker and has_date_range_pickers and has_customer_search and has_firm_search and has_gst_search and has_statistics_cards:
                    self.log_test("Enhanced Quotation List Access", True, "All enhanced list features present")
                    return True
                else:
                    missing_features = []
                    if not has_search_section: missing_features.append("Search section")
                    if not has_single_date_picker: missing_features.append("Single date picker")
                    if not has_date_range_pickers: missing_features.append("Date range pickers")
                    if not has_customer_search: missing_features.append("Customer search")
                    if not has_firm_search: missing_features.append("Firm search")
                    if not has_gst_search: missing_features.append("GST search")
                    if not has_statistics_cards: missing_features.append("Statistics cards")
                    
                    self.log_test("Enhanced Quotation List Access", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Enhanced Quotation List Access", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Enhanced Quotation List Access", False, f"Error: {str(e)}")
            return False

    def test_quotation_list_search_functionality(self):
        """Test search functionality in quotation list"""
        if not self.authenticate():
            return False
            
        try:
            # Test customer name search
            url = f"{self.base_url}/public/quotation_list_enhanced.php?search_customer=Priya"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                # Check if search parameters are preserved in form
                soup = BeautifulSoup(response.text, 'html.parser')
                customer_input = soup.find('input', {'name': 'search_customer'})
                
                if customer_input and customer_input.get('value') == 'Priya':
                    self.log_test("Quotation List Search (Customer)", True, "Customer search parameter preserved")
                else:
                    self.log_test("Quotation List Search (Customer)", False, "Search parameter not preserved")
                    return False
            else:
                self.log_test("Quotation List Search (Customer)", False, f"HTTP {response.status_code}")
                return False
            
            # Test date range search
            today = datetime.now().strftime('%Y-%m-%d')
            url = f"{self.base_url}/public/quotation_list_enhanced.php?single_date={today}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                date_input = soup.find('input', {'name': 'single_date'})
                
                if date_input and date_input.get('value') == today:
                    self.log_test("Quotation List Search (Date)", True, "Date search parameter preserved")
                    return True
                else:
                    self.log_test("Quotation List Search (Date)", False, "Date parameter not preserved")
                    return False
            else:
                self.log_test("Quotation List Search (Date)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation List Search", False, f"Error: {str(e)}")
            return False

    def test_database_schema_integration(self):
        """Test integration with enhanced database schema"""
        if not self.authenticate():
            return False
            
        try:
            # Test if enhanced views are working by accessing quotation list
            url = f"{self.base_url}/public/quotation_list_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                # Check for enhanced quotation data display
                has_firm_name_display = 'Firm Name' in response.text or 'firm_name' in response.text
                has_gst_display = 'GST' in response.text
                has_created_by_display = 'Created By' in response.text
                has_item_counts = 'Total Items' in response.text or 'tile_items' in response.text
                
                if has_firm_name_display and has_gst_display and has_created_by_display and has_item_counts:
                    self.log_test("Database Schema Integration", True, "Enhanced database schema working correctly")
                    return True
                else:
                    missing_features = []
                    if not has_firm_name_display: missing_features.append("Firm name display")
                    if not has_gst_display: missing_features.append("GST display")
                    if not has_created_by_display: missing_features.append("Created by display")
                    if not has_item_counts: missing_features.append("Item counts")
                    
                    self.log_test("Database Schema Integration", False, f"Missing schema features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Database Schema Integration", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Database Schema Integration", False, f"Error: {str(e)}")
            return False

    def test_quotation_to_invoice_conversion(self):
        """Test quotation to invoice conversion functionality"""
        if not self.authenticate():
            return False
            
        try:
            quotation_id = getattr(self, 'created_quotation_id', 1)
            url = f"{self.base_url}/public/quotation_enhanced.php?id={quotation_id}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                # Check for convert to invoice button
                has_convert_button = 'Convert to Invoice' in response.text
                has_invoice_link = 'invoice_enhanced.php' in response.text or 'convertToInvoice' in response.text
                
                if has_convert_button and has_invoice_link:
                    self.log_test("Quotation to Invoice Conversion", True, "Invoice conversion functionality present")
                    return True
                else:
                    missing_features = []
                    if not has_convert_button: missing_features.append("Convert button")
                    if not has_invoice_link: missing_features.append("Invoice link")
                    
                    self.log_test("Quotation to Invoice Conversion", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Quotation to Invoice Conversion", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation to Invoice Conversion", False, f"Error: {str(e)}")
            return False

    def test_export_functionality(self):
        """Test export functionality in quotation list"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/quotation_list_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                # Check for export functionality
                has_export_button = 'Export CSV' in response.text
                has_export_function = 'exportData()' in response.text
                has_bulk_print = 'Bulk Print' in response.text
                
                if has_export_button and has_export_function and has_bulk_print:
                    self.log_test("Export Functionality", True, "Export and bulk operations available")
                    return True
                else:
                    missing_features = []
                    if not has_export_button: missing_features.append("Export button")
                    if not has_export_function: missing_features.append("Export function")
                    if not has_bulk_print: missing_features.append("Bulk print")
                    
                    self.log_test("Export Functionality", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Export Functionality", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Export Functionality", False, f"Error: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all enhanced quotation system tests"""
        print("🧪 Starting Enhanced Quotation & Invoice System Tests")
        print("=" * 70)
        
        # Authentication test
        if not self.authenticate():
            print("❌ Cannot authenticate - aborting further tests")
            return False
        
        # Enhanced quotation access and creation tests
        self.test_enhanced_quotation_access()
        self.test_quotation_creation_validation()
        self.test_quotation_creation_success()
        
        # Calculation and functionality tests
        self.test_calculation_mode_toggle()
        self.test_stock_availability_display()
        self.test_image_display_preferences()
        
        # Enhanced list and search tests
        self.test_enhanced_quotation_list_access()
        self.test_quotation_list_search_functionality()
        
        # Integration and advanced features tests
        self.test_database_schema_integration()
        self.test_quotation_to_invoice_conversion()
        self.test_export_functionality()
        
        # Summary
        print("\n" + "=" * 70)
        print("📊 ENHANCED QUOTATION SYSTEM TEST SUMMARY")
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
        
        # List successful tests
        successful_tests = [result for result in self.test_results if result['success']]
        if successful_tests:
            print("\n✅ SUCCESSFUL TESTS:")
            for test in successful_tests:
                print(f"  • {test['test']}: {test['message']}")
        
        return passed == total

def main():
    """Main test execution"""
    tester = EnhancedQuotationTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! Enhanced Quotation & Invoice System is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()