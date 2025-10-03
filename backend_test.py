#!/usr/bin/env python3
"""
Backend Test Suite for FastAPI-based System
Tests the FastAPI backend system and verifies basic functionality.
"""

import requests
import json
import time
from urllib.parse import urljoin, urlparse, parse_qs
import re
import sys
import os
from datetime import datetime

class FastAPISystemTester:
    def __init__(self, base_url="https://tilecrm-app.preview.emergentagent.com"):
        self.base_url = base_url
        self.api_url = f"{base_url}/api"
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Content-Type': 'application/json'
        })
        self.test_results = []
        
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
        
    def test_api_connectivity(self):
        """Test basic API connectivity"""
        try:
            response = self.session.get(f"{self.api_url}/", timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('message') == 'Hello World':
                    self.log_test("API Connectivity", True, "FastAPI backend is responding correctly")
                    return True
                else:
                    self.log_test("API Connectivity", False, f"Unexpected response: {data}")
                    return False
            else:
                self.log_test("API Connectivity", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("API Connectivity", False, f"Error: {str(e)}")
            return False

    def test_status_endpoint(self):
        """Test the status endpoint functionality"""
        try:
            # Test GET status endpoint
            response = self.session.get(f"{self.api_url}/status", timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if isinstance(data, list):
                    self.log_test("Status Endpoint (GET)", True, f"Status endpoint returns list with {len(data)} items")
                else:
                    self.log_test("Status Endpoint (GET)", False, f"Expected list, got: {type(data)}")
                    return False
            else:
                self.log_test("Status Endpoint (GET)", False, f"HTTP {response.status_code}")
                return False
            
            # Test POST status endpoint
            test_data = {
                "client_name": "Test Client for Invoice System"
            }
            
            response = self.session.post(f"{self.api_url}/status", 
                                       data=json.dumps(test_data), 
                                       timeout=10)
            
            if response.status_code == 200:
                data = response.json()
                if data.get('client_name') == test_data['client_name']:
                    self.log_test("Status Endpoint (POST)", True, f"Successfully created status check with ID: {data.get('id')}")
                    return True
                else:
                    self.log_test("Status Endpoint (POST)", False, f"Unexpected response: {data}")
                    return False
            else:
                self.log_test("Status Endpoint (POST)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Status Endpoint", False, f"Error: {str(e)}")
            return False

    def test_database_connectivity(self):
        """Test database connectivity through API"""
        try:
            # Create a test status check to verify database connectivity
            test_data = {
                "client_name": "Database Connectivity Test"
            }
            
            response = self.session.post(f"{self.api_url}/status", 
                                       data=json.dumps(test_data), 
                                       timeout=10)
            
            if response.status_code == 200:
                created_item = response.json()
                
                # Now try to retrieve it
                response = self.session.get(f"{self.api_url}/status", timeout=10)
                
                if response.status_code == 200:
                    items = response.json()
                    # Check if our created item is in the list
                    found_item = None
                    for item in items:
                        if item.get('id') == created_item.get('id'):
                            found_item = item
                            break
                    
                    if found_item:
                        self.log_test("Database Connectivity", True, f"Successfully created and retrieved item from database")
                        return True
                    else:
                        self.log_test("Database Connectivity", False, "Created item not found in database")
                        return False
                else:
                    self.log_test("Database Connectivity", False, f"Failed to retrieve items: HTTP {response.status_code}")
                    return False
            else:
                self.log_test("Database Connectivity", False, f"Failed to create item: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Database Connectivity", False, f"Error: {str(e)}")
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

    # Additional helper methods can be added here if needed

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
        """Run all FastAPI system tests"""
        print("🧪 Starting FastAPI Backend System Tests")
        print("=" * 50)
        
        # Core API tests
        print("\n🔌 Testing API Connectivity...")
        self.test_api_connectivity()
        
        print("\n📊 Testing Status Endpoints...")
        self.test_status_endpoint()
        
        print("\n🗄️ Testing Database Connectivity...")
        self.test_database_connectivity()
        
        # Summary
        print("\n" + "=" * 50)
        print("📊 FASTAPI SYSTEM TEST SUMMARY")
        print("=" * 50)
        
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
    tester = FastAPISystemTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! FastAPI Backend System is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()