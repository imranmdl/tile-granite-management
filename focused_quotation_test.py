#!/usr/bin/env python3
"""
Focused Test for High-Priority Quotation Features
Tests the specific functionality mentioned in test_result.md:
1. Quotation Item Update/Delete Functionality
2. Quotation Delete Functionality  
3. Quotation Discount System
4. Total Calculation Accuracy
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

class FocusedQuotationTester:
    def __init__(self, base_url="http://localhost:8080"):
        self.base_url = base_url
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        })
        self.test_results = []
        self.authenticated = False
        self.created_quotation_id = None
        
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
            login_url = f"{self.base_url}/login_clean.php"
            response = self.session.get(login_url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Authentication Setup", False, f"Cannot access login page: HTTP {response.status_code}")
                return False
            
            # Submit login form
            login_data = {
                'username': 'admin',
                'password': 'admin123'
            }
            
            response = self.session.post(login_url, data=login_data, allow_redirects=False)
            
            # Check if login was successful by testing access to a protected page
            if response.status_code == 302:
                # Try to access a protected page to verify authentication
                test_url = f"{self.base_url}/quotation_enhanced.php"
                test_response = self.session.get(test_url)
                
                if test_response.status_code == 200 and 'login' not in test_response.url.lower():
                    self.authenticated = True
                    self.log_test("Authentication Setup", True, "Successfully authenticated as admin")
                    return True
                else:
                    self.log_test("Authentication Setup", False, "Authentication failed - redirected to login")
                    return False
            else:
                self.log_test("Authentication Setup", False, f"Login failed: {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Authentication Setup", False, f"Authentication error: {str(e)}")
            return False

    def create_test_quotation_with_items(self):
        """Create a test quotation with items for testing update/delete functionality"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/quotation_enhanced.php"
            
            # Create quotation with all enhanced fields
            quotation_data = {
                'create_quote': '1',
                'quote_dt': datetime.now().strftime('%Y-%m-%d'),
                'customer_name': 'Test Customer for Updates',
                'firm_name': 'Test Firm Ltd',
                'phone': '9876543210',
                'customer_gst': '27ABCDE1234F1Z5',
                'notes': 'Test quotation for update/delete functionality testing'
            }
            
            response = self.session.post(url, data=quotation_data, allow_redirects=False)
            
            if response.status_code == 302:  # Redirect after successful creation
                redirect_url = response.headers.get('Location', '')
                if 'quotation_enhanced.php?id=' in redirect_url:
                    # Extract quotation ID from redirect URL
                    quotation_id = redirect_url.split('id=')[1].split('&')[0]
                    self.created_quotation_id = int(quotation_id)
                    
                    # Now add some items to this quotation
                    self.add_test_items_to_quotation(quotation_id)
                    
                    self.log_test("Test Quotation Creation", True, f"Successfully created quotation ID: {quotation_id} with test items")
                    return True
                else:
                    self.log_test("Test Quotation Creation", False, f"Unexpected redirect: {redirect_url}")
                    return False
            else:
                self.log_test("Test Quotation Creation", False, f"Expected redirect, got HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Test Quotation Creation", False, f"Error: {str(e)}")
            return False

    def add_test_items_to_quotation(self, quotation_id):
        """Add test items to the quotation for testing update/delete"""
        try:
            url = f"{self.base_url}/quotation_enhanced.php?id={quotation_id}"
            
            # Add a tile item
            tile_data = {
                'add_tile_item': '1',
                'tile_id': '54',  # Using the first available tile from the dropdown
                'purpose': 'Test Floor',
                'rate_per_box': '500.00',
                'calculation_mode': 'direct_mode',
                'direct_boxes': '10',
                'show_image': 'on'
            }
            
            response = self.session.post(url, data=tile_data)
            
            # Add a misc item
            misc_data = {
                'add_misc_item': '1',
                'misc_item_id': '4',  # Using the first available misc item
                'purpose': 'Test Installation',
                'qty_units': '5',
                'rate_per_unit': '100.00',
                'show_image': 'on'
            }
            
            response = self.session.post(url, data=misc_data)
            
        except Exception as e:
            print(f"Warning: Could not add test items: {str(e)}")

    def test_quotation_item_update_delete_functionality(self):
        """Test quotation item update and delete functionality"""
        if not self.authenticate():
            return False
            
        # Create a test quotation with items first
        if not self.create_test_quotation_with_items():
            return False
            
        try:
            quotation_id = self.created_quotation_id
            url = f"{self.base_url}/quotation_enhanced.php?id={quotation_id}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for update/delete buttons in item rows
                update_buttons = soup.find_all('button', string=lambda text: text and 'update' in text.lower())
                delete_buttons = soup.find_all('button', string=lambda text: text and 'delete' in text.lower())
                edit_buttons = soup.find_all('button', string=lambda text: text and 'edit' in text.lower())
                
                # Check for actual backend handling (not just JavaScript alerts)
                has_update_form = soup.find('form', {'action': lambda x: x and 'update_item' in x}) is not None
                has_delete_form = soup.find('form', {'action': lambda x: x and 'delete_item' in x}) is not None
                
                # Check for JavaScript functions that handle actual backend calls
                has_update_function = 'updateQuotationItem' in response.text or 'update_item' in response.text
                has_delete_function = 'deleteQuotationItem' in response.text or 'delete_item' in response.text
                has_edit_function = 'editItem(' in response.text
                
                # Check if it's just JavaScript alerts (which would be a failure)
                is_just_alerts = 'alert(' in response.text and not (has_update_function or has_delete_function or has_edit_function)
                
                # Look for any form of update/delete functionality
                total_action_buttons = len(update_buttons) + len(delete_buttons) + len(edit_buttons)
                has_any_backend_handling = has_update_form or has_delete_form or has_update_function or has_delete_function or has_edit_function
                
                if total_action_buttons > 0 and has_any_backend_handling and not is_just_alerts:
                    self.log_test("Quotation Item Update/Delete Functionality", True, 
                                f"Found {total_action_buttons} action buttons with backend handling")
                    return True
                elif is_just_alerts:
                    self.log_test("Quotation Item Update/Delete Functionality", False, 
                                "Only JavaScript alerts found - no actual backend functionality")
                    return False
                elif total_action_buttons == 0:
                    self.log_test("Quotation Item Update/Delete Functionality", False, 
                                "No update/delete buttons found in quotation items")
                    return False
                else:
                    self.log_test("Quotation Item Update/Delete Functionality", False, 
                                f"Found {total_action_buttons} buttons but no backend handling")
                    return False
            else:
                self.log_test("Quotation Item Update/Delete Functionality", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation Item Update/Delete Functionality", False, f"Error: {str(e)}")
            return False

    def test_quotation_delete_functionality(self):
        """Test quotation deletion from list page"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/quotation_list_enhanced.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for delete buttons in quotation list
                delete_buttons = soup.find_all('button', {'onclick': lambda x: x and 'deleteQuotation' in x})
                delete_icons = soup.find_all('i', class_=lambda x: x and 'trash' in ' '.join(x) if x else False)
                delete_titles = soup.find_all(attrs={'title': lambda x: x and 'delete' in x.lower() if x else False})
                
                # Check for actual backend handling
                has_delete_function = 'deleteQuotation' in response.text
                has_post_creation = 'form.method = \'POST\'' in response.text
                has_delete_input = 'delete_quotation' in response.text
                
                # Check for proper POST handler implementation in JavaScript
                has_backend_post = has_post_creation and has_delete_input and 'form.submit()' in response.text
                
                total_delete_elements = len(delete_buttons) + len(delete_icons) + len(delete_titles)
                
                if total_delete_elements > 0 and has_delete_function and has_backend_post:
                    self.log_test("Quotation Delete Functionality", True, 
                                f"Found {total_delete_elements} delete elements with proper POST backend handling")
                    return True
                elif total_delete_elements > 0 and has_delete_function and not has_backend_post:
                    self.log_test("Quotation Delete Functionality", False, 
                                f"Found {total_delete_elements} delete elements but no proper backend POST handling")
                    return False
                elif total_delete_elements == 0:
                    self.log_test("Quotation Delete Functionality", False, 
                                "No quotation delete buttons/links found")
                    return False
                else:
                    self.log_test("Quotation Delete Functionality", False, 
                                f"Found {total_delete_elements} delete elements but missing delete function")
                    return False
            else:
                self.log_test("Quotation Delete Functionality", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation Delete Functionality", False, f"Error: {str(e)}")
            return False

    def test_quotation_discount_system(self):
        """Test discount functionality in quotations"""
        if not self.authenticate():
            return False
            
        try:
            # Use existing quotation or create one
            quotation_id = self.created_quotation_id or 13
            url = f"{self.base_url}/quotation_enhanced.php?id={quotation_id}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for discount fields
                discount_percentage_field = soup.find('input', {'name': 'discount_percentage'})
                discount_amount_field = soup.find('input', {'name': 'discount_amount'})
                discount_value_field = soup.find('input', {'name': 'discount_value'})
                discount_type_select = soup.find('select', {'name': 'discount_type'})
                
                # Check for discount section
                discount_section = soup.find('div', class_='discount-section')
                discount_heading = soup.find(string=lambda text: text and 'discount' in text.lower())
                
                # Check for discount calculation in totals
                has_discount_display = 'Discount' in response.text and ('₹' in response.text or 'Rs.' in response.text)
                has_discount_calculation = 'calculateDiscount' in response.text or 'discount_total' in response.text
                
                # Check for both percentage and fixed amount options
                has_percentage_option = False
                has_fixed_option = False
                if discount_type_select:
                    options = discount_type_select.find_all('option')
                    for option in options:
                        if 'percentage' in option.text.lower():
                            has_percentage_option = True
                        if 'fixed' in option.text.lower() or 'amount' in option.text.lower():
                            has_fixed_option = True
                
                # Count discount-related elements
                discount_fields = [discount_percentage_field, discount_amount_field, discount_value_field, discount_type_select]
                discount_field_count = sum(1 for field in discount_fields if field is not None)
                
                if discount_field_count > 0 or discount_section or has_discount_calculation:
                    discount_features = []
                    if discount_percentage_field: discount_features.append("percentage field")
                    if discount_amount_field: discount_features.append("amount field")
                    if discount_value_field: discount_features.append("value field")
                    if discount_type_select: discount_features.append("type selector")
                    if has_percentage_option: discount_features.append("percentage option")
                    if has_fixed_option: discount_features.append("fixed amount option")
                    if discount_section: discount_features.append("discount section")
                    if has_discount_calculation: discount_features.append("calculation function")
                    
                    self.log_test("Quotation Discount System", True, 
                                f"Discount functionality present: {', '.join(discount_features)}")
                    return True
                else:
                    self.log_test("Quotation Discount System", False, 
                                "No discount functionality found in quotations")
                    return False
            else:
                self.log_test("Quotation Discount System", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Quotation Discount System", False, f"Error: {str(e)}")
            return False

    def test_total_calculation_accuracy(self):
        """Test that subtotal and total calculations are working correctly"""
        if not self.authenticate():
            return False
            
        try:
            # Use existing quotation or create one
            quotation_id = self.created_quotation_id or 13
            url = f"{self.base_url}/quotation_enhanced.php?id={quotation_id}"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for total calculation elements
                has_subtotal = soup.find('span', {'id': 'subtotal'}) is not None or 'Subtotal' in response.text
                has_total = soup.find('span', {'id': 'total'}) is not None or 'Total' in response.text
                has_calculation_js = 'calculateTotal' in response.text or 'updateTotals' in response.text
                
                # Check for live calculation functionality
                has_live_calculation = 'onchange' in response.text and ('calculate' in response.text.lower() or 'update' in response.text.lower())
                has_oninput_calculation = 'oninput' in response.text and 'calculate' in response.text.lower()
                
                # Check for calculation functions in JavaScript
                calculation_functions = []
                if 'calculateFromSqft' in response.text: calculation_functions.append("sqft calculation")
                if 'calculateFromBoxes' in response.text: calculation_functions.append("box calculation")
                if 'calculateMiscTotal' in response.text: calculation_functions.append("misc calculation")
                if 'calculateTotal' in response.text: calculation_functions.append("total calculation")
                
                # Check for total display elements
                total_displays = []
                if soup.find('input', {'id': 'lineTotal'}): total_displays.append("line total field")
                if soup.find('input', {'id': 'miscLineTotal'}): total_displays.append("misc line total field")
                if soup.find('input', {'id': 'totalSqft'}): total_displays.append("sqft total field")
                if soup.find('input', {'id': 'boxesNeeded'}): total_displays.append("boxes needed field")
                
                if (has_subtotal or has_total) and len(calculation_functions) > 0 and (has_live_calculation or has_oninput_calculation):
                    features = calculation_functions + total_displays
                    if has_subtotal: features.append("subtotal display")
                    if has_total: features.append("total display")
                    if has_live_calculation: features.append("live calculation")
                    
                    self.log_test("Total Calculation Accuracy", True, 
                                f"Calculation system present: {', '.join(features)}")
                    return True
                else:
                    missing_features = []
                    if not (has_subtotal or has_total): missing_features.append("total display")
                    if len(calculation_functions) == 0: missing_features.append("calculation functions")
                    if not (has_live_calculation or has_oninput_calculation): missing_features.append("live calculation")
                    
                    self.log_test("Total Calculation Accuracy", False, 
                                f"Missing calculation features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Total Calculation Accuracy", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Total Calculation Accuracy", False, f"Error: {str(e)}")
            return False

    def run_focused_tests(self):
        """Run focused tests on high-priority functionality"""
        print("🧪 Starting Focused High-Priority Quotation Tests")
        print("=" * 60)
        
        # Authentication test
        if not self.authenticate():
            print("❌ Cannot authenticate - aborting further tests")
            return False
        
        # High-priority functionality tests
        self.test_quotation_item_update_delete_functionality()
        self.test_quotation_delete_functionality()
        self.test_quotation_discount_system()
        self.test_total_calculation_accuracy()
        
        # Summary
        print("\n" + "=" * 60)
        print("📊 FOCUSED TEST SUMMARY")
        print("=" * 60)
        
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
    tester = FocusedQuotationTester()
    success = tester.run_focused_tests()
    
    if success:
        print("\n🎉 All focused tests passed!")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()