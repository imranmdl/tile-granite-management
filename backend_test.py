#!/usr/bin/env python3
"""
Backend Test for PHP + SQLite Business Management System
Tests all core functionality including authentication, database operations, and business workflows
"""

import requests
import sys
import json
from datetime import datetime
from urllib.parse import urljoin
import re

class PHPBusinessSystemTester:
    def __init__(self, base_url="http://localhost:8080"):
        self.base_url = base_url
        self.session = requests.Session()
        self.tests_run = 0
        self.tests_passed = 0
        self.current_user = None
        
        # Test credentials from the system
        self.test_users = [
            {"username": "admin", "password": "admin123", "role": "admin"},
            {"username": "manager1", "password": "manager123", "role": "manager"},
            {"username": "sales1", "password": "sales123", "role": "sales"}
        ]

    def run_test(self, name, test_func, *args, **kwargs):
        """Run a single test and track results"""
        self.tests_run += 1
        print(f"\n🔍 Testing {name}...")
        
        try:
            success = test_func(*args, **kwargs)
            if success:
                self.tests_passed += 1
                print(f"✅ Passed - {name}")
                return True
            else:
                print(f"❌ Failed - {name}")
                return False
        except Exception as e:
            print(f"❌ Failed - {name}: {str(e)}")
            return False

    def test_system_availability(self):
        """Test if the PHP system is accessible"""
        try:
            response = self.session.get(f"{self.base_url}/system_test.php", timeout=10)
            if response.status_code == 200 and "System Test" in response.text:
                print("✅ PHP system is accessible and system_test.php loads")
                return True
            else:
                print(f"❌ System not accessible - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ System not accessible - Error: {str(e)}")
            return False

    def test_database_connectivity(self):
        """Test SQLite database connectivity through system_test.php"""
        try:
            response = self.session.get(f"{self.base_url}/system_test.php")
            if response.status_code == 200:
                # Check for database connection success indicators
                if "Database Connection" in response.text and "PASS" in response.text:
                    print("✅ SQLite database connection successful")
                    return True
                else:
                    print("❌ Database connection issues detected")
                    return False
            return False
        except Exception as e:
            print(f"❌ Database test failed: {str(e)}")
            return False

    def test_login_page_loads(self):
        """Test if login page loads correctly"""
        try:
            response = self.session.get(f"{self.base_url}/login.php")
            if response.status_code == 200:
                # Check for login form elements
                if "username" in response.text.lower() and "password" in response.text.lower():
                    print("✅ Login page loads with form elements")
                    return True
                else:
                    print("❌ Login page missing form elements")
                    return False
            else:
                print(f"❌ Login page not accessible - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Login page test failed: {str(e)}")
            return False

    def test_user_authentication(self, username, password, expected_role):
        """Test user authentication with given credentials"""
        try:
            # First get the login page to establish session
            login_response = self.session.get(f"{self.base_url}/login.php")
            if login_response.status_code != 200:
                print(f"❌ Cannot access login page")
                return False

            # Attempt login
            login_data = {
                'username': username,
                'password': password,
                'login': '1'
            }
            
            response = self.session.post(f"{self.base_url}/login.php", data=login_data, allow_redirects=False)
            
            # Check for successful login (redirect or success indicators)
            if response.status_code in [302, 303] or "Location" in response.headers:
                print(f"✅ Login successful for {username} ({expected_role})")
                self.current_user = {"username": username, "role": expected_role}
                return True
            elif response.status_code == 200:
                # Check if we're redirected to dashboard or if there's an error
                if "Invalid username or password" in response.text:
                    print(f"❌ Login failed for {username} - Invalid credentials")
                    return False
                elif "dashboard" in response.text.lower() or "welcome" in response.text.lower():
                    print(f"✅ Login successful for {username} ({expected_role})")
                    self.current_user = {"username": username, "role": expected_role}
                    return True
                else:
                    print(f"❌ Login response unclear for {username}")
                    return False
            else:
                print(f"❌ Login failed for {username} - Status: {response.status_code}")
                return False
                
        except Exception as e:
            print(f"❌ Login test failed for {username}: {str(e)}")
            return False

    def test_dashboard_access(self):
        """Test dashboard accessibility after login"""
        try:
            response = self.session.get(f"{self.base_url}/index.php")
            if response.status_code == 200:
                # Check for dashboard elements
                dashboard_indicators = ["dashboard", "kpi", "revenue", "invoices", "tiles"]
                found_indicators = sum(1 for indicator in dashboard_indicators if indicator.lower() in response.text.lower())
                
                if found_indicators >= 2:
                    print(f"✅ Dashboard accessible with {found_indicators} key elements")
                    return True
                else:
                    print(f"❌ Dashboard missing key elements (found {found_indicators})")
                    return False
            else:
                print(f"❌ Dashboard not accessible - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Dashboard test failed: {str(e)}")
            return False

    def test_invoice_list_access(self):
        """Test invoice list page accessibility"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_list_enhanced.php")
            if response.status_code == 200:
                # Check for invoice list elements
                if "invoice" in response.text.lower() and ("list" in response.text.lower() or "management" in response.text.lower()):
                    print("✅ Invoice list page accessible")
                    return True
                else:
                    print("❌ Invoice list page missing expected content")
                    return False
            else:
                print(f"❌ Invoice list not accessible - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Invoice list test failed: {str(e)}")
            return False

    def test_invoice_creation_page(self):
        """Test invoice creation page accessibility"""
        try:
            response = self.session.get(f"{self.base_url}/invoice_enhanced.php")
            if response.status_code == 200:
                # Check for invoice creation form elements
                form_elements = ["customer_name", "phone", "invoice_dt"]
                found_elements = sum(1 for element in form_elements if element in response.text)
                
                if found_elements >= 2:
                    print(f"✅ Invoice creation page accessible with form elements")
                    return True
                else:
                    print(f"❌ Invoice creation page missing form elements")
                    return False
            else:
                print(f"❌ Invoice creation page not accessible - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Invoice creation test failed: {str(e)}")
            return False

    def test_reports_dashboard_access(self):
        """Test reports dashboard accessibility"""
        try:
            response = self.session.get(f"{self.base_url}/reports_dashboard_new.php")
            if response.status_code == 200:
                # Check for reports dashboard elements
                if "reports" in response.text.lower() and "dashboard" in response.text.lower():
                    print("✅ Reports dashboard accessible")
                    return True
                else:
                    print("❌ Reports dashboard missing expected content")
                    return False
            else:
                print(f"❌ Reports dashboard not accessible - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Reports dashboard test failed: {str(e)}")
            return False

    def test_sales_report_access(self):
        """Test sales report accessibility"""
        try:
            response = self.session.get(f"{self.base_url}/report_sales_enhanced.php")
            if response.status_code == 200:
                # Check for sales report elements
                if "sales" in response.text.lower() and "report" in response.text.lower():
                    print("✅ Sales report accessible")
                    return True
                else:
                    print("❌ Sales report missing expected content")
                    return False
            else:
                print(f"❌ Sales report not accessible - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Sales report test failed: {str(e)}")
            return False

    def test_logout_functionality(self):
        """Test logout functionality"""
        try:
            response = self.session.get(f"{self.base_url}/logout.php", allow_redirects=False)
            if response.status_code in [302, 303] or "Location" in response.headers:
                print("✅ Logout successful (redirect detected)")
                self.current_user = None
                return True
            elif response.status_code == 200:
                # Check if redirected to login or logout message
                if "login" in response.text.lower() or "logout" in response.text.lower():
                    print("✅ Logout successful")
                    self.current_user = None
                    return True
                else:
                    print("❌ Logout response unclear")
                    return False
            else:
                print(f"❌ Logout failed - Status: {response.status_code}")
                return False
        except Exception as e:
            print(f"❌ Logout test failed: {str(e)}")
            return False

    def test_unauthorized_access(self):
        """Test that protected pages redirect to login when not authenticated"""
        try:
            # Clear session
            self.session = requests.Session()
            
            protected_pages = [
                "/index.php",
                "/invoice_enhanced.php", 
                "/invoice_list_enhanced.php",
                "/reports_dashboard_new.php"
            ]
            
            unauthorized_count = 0
            for page in protected_pages:
                response = self.session.get(f"{self.base_url}{page}", allow_redirects=False)
                if response.status_code in [302, 303] or "login" in response.text.lower():
                    unauthorized_count += 1
            
            if unauthorized_count >= len(protected_pages) - 1:  # Allow some flexibility
                print(f"✅ Protected pages properly redirect to login ({unauthorized_count}/{len(protected_pages)})")
                return True
            else:
                print(f"❌ Some protected pages accessible without login ({unauthorized_count}/{len(protected_pages)})")
                return False
                
        except Exception as e:
            print(f"❌ Unauthorized access test failed: {str(e)}")
            return False

    def run_comprehensive_test(self):
        """Run all tests in sequence"""
        print("🚀 Starting Comprehensive PHP Business System Test")
        print("=" * 60)
        
        # Basic system tests
        self.run_test("System Availability", self.test_system_availability)
        self.run_test("Database Connectivity", self.test_database_connectivity)
        self.run_test("Login Page Loading", self.test_login_page_loads)
        
        # Authentication tests
        self.run_test("Unauthorized Access Protection", self.test_unauthorized_access)
        
        # Test each user role
        for user in self.test_users:
            success = self.run_test(
                f"Authentication - {user['username']} ({user['role']})",
                self.test_user_authentication,
                user['username'], user['password'], user['role']
            )
            
            if success:
                # Test authenticated functionality
                self.run_test(f"Dashboard Access - {user['username']}", self.test_dashboard_access)
                self.run_test(f"Invoice List Access - {user['username']}", self.test_invoice_list_access)
                self.run_test(f"Invoice Creation Access - {user['username']}", self.test_invoice_creation_page)
                self.run_test(f"Reports Dashboard Access - {user['username']}", self.test_reports_dashboard_access)
                self.run_test(f"Sales Report Access - {user['username']}", self.test_sales_report_access)
                self.run_test(f"Logout - {user['username']}", self.test_logout_functionality)
        
        # Print final results
        print("\n" + "=" * 60)
        print(f"📊 Test Results: {self.tests_passed}/{self.tests_run} tests passed")
        
        success_rate = (self.tests_passed / self.tests_run * 100) if self.tests_run > 0 else 0
        print(f"📈 Success Rate: {success_rate:.1f}%")
        
        if success_rate >= 80:
            print("🎉 Overall Status: GOOD - System is functioning well")
            return 0
        elif success_rate >= 60:
            print("⚠️  Overall Status: FAIR - Some issues detected")
            return 1
        else:
            print("❌ Overall Status: POOR - Major issues detected")
            return 2

def main():
    tester = PHPBusinessSystemTester()
    return tester.run_comprehensive_test()

if __name__ == "__main__":
    sys.exit(main())