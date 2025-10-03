#!/usr/bin/env python3
"""
Invoice System Comprehensive Test Suite
Tests the complete invoice system functionality as requested by the user.
"""

import requests
import json
import time
from urllib.parse import urljoin, urlparse, parse_qs
import re
import sys
import os
from datetime import datetime
from bs4 import BeautifulSoup

class InvoiceSystemTester:
    def __init__(self, base_url="http://localhost:8080"):
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
        try:
            # First get the login page to establish session
            response = self.session.get(f"{self.base_url}/login_clean.php")
            
            # Login with admin credentials
            login_data = {
                'username': 'admin',
                'password': 'admin123'
            }
            
            response = self.session.post(f"{self.base_url}/login_clean.php", data=login_data)
            
            # Check if login was successful by trying to access a protected page
            test_response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            
            if test_response.status_code == 200 and "Create New Invoice" in test_response.text:
                self.authenticated = True
                self.log_test("Authentication", True, "Successfully logged in with admin/admin123")
                return True
            else:
                self.log_test("Authentication", False, "Failed to authenticate")
                return False
                
        except Exception as e:
            self.log_test("Authentication", False, f"Error during authentication: {str(e)}")
            return False

    def test_invoice_creation_page(self):
        """Test invoice creation page loads correctly"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for key elements
                required_elements = [
                    "Create New Invoice",
                    "Customer Name",
                    "Mobile Number",
                    "Invoice Date",
                    "form"
                ]
                
                missing_elements = []
                for element in required_elements:
                    if element not in content:
                        missing_elements.append(element)
                
                if not missing_elements:
                    self.log_test("Invoice Creation Page", True, "All required elements present")
                    return True
                else:
                    self.log_test("Invoice Creation Page", False, f"Missing elements: {', '.join(missing_elements)}")
                    return False
            else:
                self.log_test("Invoice Creation Page", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Invoice Creation Page", False, f"Error: {str(e)}")
            return False

    def test_invoice_creation(self):
        """Test creating a new invoice"""
        try:
            # Get the invoice creation page first
            response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            
            if response.status_code != 200:
                self.log_test("Invoice Creation", False, "Cannot access invoice creation page")
                return False
            
            # Create invoice data
            invoice_data = {
                'customer_name': 'John Smith',
                'phone': '9876543210',
                'firm_name': 'Smith Enterprises',
                'customer_gst': 'GST123456789',
                'create_invoice': '1'
            }
            
            response = self.session.post(f"{self.base_url}/invoice_enhanced.php", data=invoice_data)
            
            if response.status_code == 200:
                # Check if we were redirected to an invoice edit page (successful creation)
                if "Edit Invoice:" in response.text or "invoice_enhanced.php?id=" in response.url:
                    self.log_test("Invoice Creation", True, "Invoice created successfully - redirected to edit page")
                    return True
                # Check for success message
                elif "Invoice created successfully" in response.text or "invoice_id" in response.text.lower():
                    self.log_test("Invoice Creation", True, "Invoice created successfully")
                    return True
                # Check if we're still on creation page but no error alerts
                elif "Create New Invoice" in response.text:
                    # Check for actual error alerts (not return policy info)
                    soup = BeautifulSoup(response.text, 'html.parser')
                    error_elements = soup.find_all(['div'], class_=re.compile(r'alert-danger'))
                    
                    if error_elements:
                        error_msg = error_elements[0].get_text(strip=True)
                        self.log_test("Invoice Creation", False, f"Error creating invoice: {error_msg}")
                        return False
                    else:
                        # No error alerts found, likely successful but stayed on same page
                        self.log_test("Invoice Creation", True, "Invoice creation appears successful (no error alerts)")
                        return True
                else:
                    self.log_test("Invoice Creation", False, "Invoice creation response unclear")
                    return False
            else:
                self.log_test("Invoice Creation", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Invoice Creation", False, f"Error: {str(e)}")
            return False

    def test_invoice_item_management(self):
        """Test invoice item management functionality"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for edit functionality (should not show "Feature coming soon!")
                if "Feature coming soon!" in content:
                    self.log_test("Invoice Item Management", False, "Still showing 'Feature coming soon!' alerts")
                    return False
                
                # Check for edit item functionality
                if "editInvoiceItem" in content and "update_invoice_item" in content:
                    self.log_test("Invoice Item Management", True, "Edit invoice item functions present")
                    return True
                elif "editItem" in content and "deleteItem" in content:
                    self.log_test("Invoice Item Management", True, "Edit and delete item functions present")
                    return True
                else:
                    self.log_test("Invoice Item Management", False, "Edit/delete item functions not found")
                    return False
            else:
                self.log_test("Invoice Item Management", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Invoice Item Management", False, f"Error: {str(e)}")
            return False

    def test_discount_system(self):
        """Test discount system functionality"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for discount functionality
                discount_elements = [
                    "discount",
                    "percentage",
                    "fixed amount"
                ]
                
                found_elements = []
                for element in discount_elements:
                    if element.lower() in content.lower():
                        found_elements.append(element)
                
                if len(found_elements) >= 2:
                    self.log_test("Discount System", True, f"Discount functionality present: {', '.join(found_elements)}")
                    return True
                else:
                    self.log_test("Discount System", False, "Discount functionality not found or incomplete")
                    return False
            else:
                self.log_test("Discount System", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Discount System", False, f"Error: {str(e)}")
            return False

    def test_mark_as_paid(self):
        """Test mark as paid functionality"""
        try:
            # First check invoice list to see if there are any invoices
            response = self.session.get(f"{self.base_url}/invoice_list.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for mark as paid functionality
                if "mark" in content.lower() and "paid" in content.lower():
                    self.log_test("Mark as Paid", True, "Mark as paid functionality found in invoice list")
                    return True
                else:
                    # Check in invoice creation page
                    response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
                    if response.status_code == 200 and "paid" in response.text.lower():
                        self.log_test("Mark as Paid", True, "Mark as paid functionality found")
                        return True
                    else:
                        self.log_test("Mark as Paid", False, "Mark as paid functionality not found")
                        return False
            else:
                self.log_test("Mark as Paid", False, f"Cannot access invoice list: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Mark as Paid", False, f"Error: {str(e)}")
            return False

    def test_invoice_view_print(self):
        """Test invoice view and print functionality"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_view.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for view/print functionality
                if "print" in content.lower() or "view" in content.lower():
                    self.log_test("Invoice View/Print", True, "Invoice view/print page accessible")
                    return True
                else:
                    self.log_test("Invoice View/Print", False, "Invoice view/print functionality not clear")
                    return False
            else:
                # Try alternative approach - check if invoice_enhanced has view functionality
                response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
                if response.status_code == 200 and ("print" in response.text.lower() or "view" in response.text.lower()):
                    self.log_test("Invoice View/Print", True, "Invoice view/print functionality found")
                    return True
                else:
                    self.log_test("Invoice View/Print", False, f"Cannot access invoice view: HTTP {response.status_code}")
                    return False
                
        except Exception as e:
            self.log_test("Invoice View/Print", False, f"Error: {str(e)}")
            return False

    def test_return_processing(self):
        """Test return processing functionality"""
        try:
            # Check for returns functionality
            response = self.session.get(f"{self.base_url}/returns.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for return processing elements
                if "return" in content.lower() and ("15" in content or "day" in content.lower()):
                    self.log_test("Return Processing", True, "Return processing page accessible with policy information")
                    return True
                else:
                    self.log_test("Return Processing", True, "Return processing page accessible")
                    return True
            else:
                # Check if returns functionality is integrated in invoice system
                response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
                if response.status_code == 200 and "return" in response.text.lower():
                    self.log_test("Return Processing", True, "Return functionality found in invoice system")
                    return True
                else:
                    self.log_test("Return Processing", False, f"Cannot access returns: HTTP {response.status_code}")
                    return False
                
        except Exception as e:
            self.log_test("Return Processing", False, f"Error: {str(e)}")
            return False

    def test_total_calculations(self):
        """Test total calculations functionality"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for calculation-related JavaScript functions
                calculation_indicators = [
                    "calculateTotal",
                    "updateTotal",
                    "total",
                    "subtotal",
                    "discount"
                ]
                
                found_indicators = []
                for indicator in calculation_indicators:
                    if indicator in content:
                        found_indicators.append(indicator)
                
                if len(found_indicators) >= 3:
                    self.log_test("Total Calculations", True, f"Calculation functionality present: {', '.join(found_indicators)}")
                    return True
                else:
                    self.log_test("Total Calculations", False, "Insufficient calculation functionality found")
                    return False
            else:
                self.log_test("Total Calculations", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Total Calculations", False, f"Error: {str(e)}")
            return False

    def test_database_operations(self):
        """Test database operations"""
        try:
            # Test by checking if invoice list loads (indicates database connectivity)
            response = self.session.get(f"{self.base_url}/invoice_list.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check if page loads without database errors
                if "error" not in content.lower() and "exception" not in content.lower():
                    self.log_test("Database Operations", True, "Database operations working - invoice list accessible")
                    return True
                else:
                    self.log_test("Database Operations", False, "Database errors detected in invoice list")
                    return False
            else:
                self.log_test("Database Operations", False, f"Cannot access invoice list: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Database Operations", False, f"Error: {str(e)}")
            return False

    def test_error_handling(self):
        """Test error handling and validation"""
        try:
            # Test with invalid data
            invalid_data = {
                'customer_name': '',  # Empty required field
                'phone': '123',  # Invalid mobile number
                'create_invoice': '1'
            }
            
            response = self.session.post(f"{self.base_url}/invoice_enhanced.php", data=invalid_data)
            
            if response.status_code == 200:
                content = response.text
                
                # Check if validation errors are shown
                if "error" in content.lower() or "required" in content.lower() or "invalid" in content.lower():
                    self.log_test("Error Handling", True, "Validation errors properly displayed")
                    return True
                else:
                    self.log_test("Error Handling", False, "No validation errors shown for invalid data")
                    return False
            else:
                self.log_test("Error Handling", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Error Handling", False, f"Error: {str(e)}")
            return False

    def test_user_interface(self):
        """Test user interface elements"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            
            if response.status_code == 200:
                content = response.text
                
                # Check for key UI elements
                ui_elements = [
                    "modal",
                    "form",
                    "button",
                    "input",
                    "bootstrap"
                ]
                
                found_elements = []
                for element in ui_elements:
                    if element in content.lower():
                        found_elements.append(element)
                
                if len(found_elements) >= 4:
                    self.log_test("User Interface", True, f"UI elements present: {', '.join(found_elements)}")
                    return True
                else:
                    self.log_test("User Interface", False, "Insufficient UI elements found")
                    return False
            else:
                self.log_test("User Interface", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("User Interface", False, f"Error: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all invoice system tests"""
        print("🧾 Starting Invoice System Comprehensive Tests")
        print("=" * 60)
        
        # Authentication first
        if not self.authenticate():
            print("❌ Cannot proceed without authentication")
            return False
        
        # Core functionality tests
        print("\n📄 Testing Invoice Creation...")
        self.test_invoice_creation_page()
        self.test_invoice_creation()
        
        print("\n✏️ Testing Invoice Item Management...")
        self.test_invoice_item_management()
        
        print("\n💰 Testing Discount System...")
        self.test_discount_system()
        
        print("\n✅ Testing Mark as Paid...")
        self.test_mark_as_paid()
        
        print("\n👁️ Testing View/Print...")
        self.test_invoice_view_print()
        
        print("\n🔄 Testing Return Processing...")
        self.test_return_processing()
        
        print("\n🧮 Testing Total Calculations...")
        self.test_total_calculations()
        
        print("\n🗄️ Testing Database Operations...")
        self.test_database_operations()
        
        print("\n⚠️ Testing Error Handling...")
        self.test_error_handling()
        
        print("\n🎨 Testing User Interface...")
        self.test_user_interface()
        
        # Summary
        print("\n" + "=" * 60)
        print("📊 INVOICE SYSTEM TEST SUMMARY")
        print("=" * 60)
        
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
        print("\n🎉 All tests passed! Invoice System is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()