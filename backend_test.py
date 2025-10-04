#!/usr/bin/env python3
"""
PHP Business Management System Test Suite
Tests the PHP-based invoice management system running on localhost:8080
"""

import requests
import json
import sys
import re
from datetime import datetime

class PHPBusinessSystemTester:
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

    def test_authentication_system(self):
        """Test admin/admin123 login functionality"""
        try:
            # First, get the login page
            login_response = self.session.get(f"{self.base_url}/login.php", timeout=10)
            if login_response.status_code != 200:
                self.log_test("Authentication System", False, "Login page not accessible")
                return False
            
            # Check if login form exists
            if 'username' not in login_response.text or 'password' not in login_response.text:
                self.log_test("Authentication System", False, "Login form not found")
                return False
            
            # Attempt login with admin/admin123
            login_data = {
                'username': 'admin',
                'password': 'admin123'
            }
            
            auth_response = self.session.post(f"{self.base_url}/login.php", data=login_data, timeout=10)
            
            # Check if login was successful (should redirect or show dashboard)
            if auth_response.status_code == 200 and ('dashboard' in auth_response.text.lower() or 'logout' in auth_response.text.lower()):
                self.authenticated = True
                self.log_test("Authentication System", True, "Admin login successful - dashboard accessible")
                return True
            elif auth_response.status_code == 302:
                # Follow redirect
                redirect_response = self.session.get(f"{self.base_url}/", timeout=10)
                if redirect_response.status_code == 200 and ('dashboard' in redirect_response.text.lower() or 'logout' in redirect_response.text.lower()):
                    self.authenticated = True
                    self.log_test("Authentication System", True, "Admin login successful - redirected to dashboard")
                    return True
            
            self.log_test("Authentication System", False, "Login failed - invalid credentials or system error")
            return False
            
        except Exception as e:
            self.log_test("Authentication System", False, f"Error during authentication: {str(e)}")
            return False

    def test_quotation_enhanced_php(self):
        """Test quotation_enhanced.php - previously had parse errors"""
        if not self.authenticated:
            self.log_test("quotation_enhanced.php Validation", False, "Authentication required")
            return False
            
        try:
            # Test basic access
            response = self.session.get(f"{self.base_url}/public/quotation_enhanced.php", timeout=10)
            
            if response.status_code == 200:
                # Check for PHP syntax errors
                if 'Parse error' in response.text or 'Fatal error' in response.text:
                    self.log_test("quotation_enhanced.php Validation", False, 
                                "PHP syntax errors still present",
                                "Parse or fatal errors found in response")
                    return False
                
                # Check if page loads properly (should have form elements or quotation content)
                if 'quotation' in response.text.lower() or 'form' in response.text.lower():
                    self.log_test("quotation_enhanced.php Validation", True, 
                                "File loads without syntax errors - quotation interface accessible")
                    return True
                else:
                    self.log_test("quotation_enhanced.php Validation", False, 
                                "File loads but content appears incomplete")
                    return False
            else:
                self.log_test("quotation_enhanced.php Validation", False, 
                            f"HTTP {response.status_code} - file not accessible")
                return False
                
        except Exception as e:
            self.log_test("quotation_enhanced.php Validation", False, f"Error: {str(e)}")
            return False

    def test_item_profit_php(self):
        """Test item_profit.php - previously had 'rate_per_box' column errors"""
        if not self.authenticated:
            self.log_test("item_profit.php Database Query Validation", False, "Authentication required")
            return False
            
        try:
            response = self.session.get(f"{self.base_url}/public/item_profit.php", timeout=10)
            
            if response.status_code == 200:
                # Check for database column errors
                if 'rate_per_box' in response.text and 'error' in response.text.lower():
                    self.log_test("item_profit.php Database Query Validation", False, 
                                "rate_per_box column errors still present")
                    return False
                
                # Check for other database errors
                if 'database error' in response.text.lower() or 'sql error' in response.text.lower():
                    self.log_test("item_profit.php Database Query Validation", False, 
                                "Database query errors found")
                    return False
                
                # Check if page loads with profit data or form
                if 'profit' in response.text.lower() or 'item' in response.text.lower():
                    self.log_test("item_profit.php Database Query Validation", True, 
                                "Database queries execute without rate_per_box errors")
                    return True
                else:
                    self.log_test("item_profit.php Database Query Validation", False, 
                                "Page loads but no profit/item content found")
                    return False
            else:
                self.log_test("item_profit.php Database Query Validation", False, 
                            f"HTTP {response.status_code} - file not accessible")
                return False
                
        except Exception as e:
            self.log_test("item_profit.php Database Query Validation", False, f"Error: {str(e)}")
            return False

    def test_database_connectivity(self):
        """Test database connectivity through current system"""
        try:
            # Test MongoDB connectivity through FastAPI
            response = self.session.get(f"{self.api_url}/status", timeout=10)
            if response.status_code == 200:
                data = response.json()
                if isinstance(data, list) and len(data) > 0:
                    self.log_test("Database Connectivity", True, 
                                f"MongoDB connected via FastAPI - {len(data)} status records found")
                    return True
            
            self.log_test("Database Connectivity", False, "No database connectivity through current system")
            return False
            
        except Exception as e:
            self.log_test("Database Connectivity", False, f"Error: {str(e)}")
            return False

    def test_expected_endpoints(self):
        """Test if expected PHP endpoints exist in current system"""
        expected_endpoints = [
            '/quotation_enhanced.php?id=13',
            '/item_profit.php',
            '/quotation_profit.php', 
            '/damage_report.php',
            '/report_inventory.php'
        ]
        
        working_endpoints = []
        for endpoint in expected_endpoints:
            try:
                response = self.session.get(f"{self.base_url}{endpoint}", timeout=5)
                # Check if we get actual PHP content, not React app
                if response.status_code == 200 and '<?php' in response.text and 'doctype html' not in response.text:
                    working_endpoints.append(endpoint)
            except:
                pass
        
        if working_endpoints:
            self.log_test("Expected Endpoints", True, f"Working endpoints: {', '.join(working_endpoints)}")
            return True
        else:
            self.log_test("Expected Endpoints", False, 
                        "CRITICAL: None of the expected PHP endpoints are accessible",
                        "All requests return React app instead of PHP execution")
            return False

    def test_port_8080_access(self):
        """Test if there's a PHP server on port 8080 as user expects"""
        try:
            # Try localhost:8080 (user mentioned this in requirements)
            test_urls = [
                "http://localhost:8080",
                "http://localhost:8080/quotation_enhanced.php",
                f"{self.base_url}:8080",
                f"{self.base_url}:8080/quotation_enhanced.php"
            ]
            
            for url in test_urls:
                try:
                    response = self.session.get(url, timeout=5)
                    if response.status_code == 200 and 'php' in response.text.lower():
                        self.log_test("Port 8080 Access", True, f"PHP server found at {url}")
                        return True
                except:
                    continue
            
            self.log_test("Port 8080 Access", False, 
                        "No PHP server found on port 8080",
                        "User expected PHP system on localhost:8080 but no server found")
            return False
            
        except Exception as e:
            self.log_test("Port 8080 Access", False, f"Error: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all critical error resolution tests"""
        print("🧪 Starting Critical Error Resolution Testing")
        print("Testing for PHP system issues as requested by user")
        print("=" * 70)
        
        # Test system architecture
        print("\n🏗️ Testing System Architecture...")
        self.test_system_architecture()
        
        # Test PHP file accessibility
        print("\n📄 Testing PHP File Accessibility...")
        self.test_php_file_accessibility()
        
        # Test authentication
        print("\n🔐 Testing Authentication System...")
        self.test_authentication_system()
        
        # Test database
        print("\n🗄️ Testing Database Connectivity...")
        self.test_database_connectivity()
        
        # Test expected endpoints
        print("\n🌐 Testing Expected Endpoints...")
        self.test_expected_endpoints()
        
        # Test port 8080
        print("\n🔌 Testing Port 8080 Access...")
        self.test_port_8080_access()
        
        # Summary
        print("\n" + "=" * 70)
        print("📊 CRITICAL ERROR RESOLUTION TEST SUMMARY")
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
            print("\n❌ CRITICAL ISSUES FOUND:")
            for test in failed_tests:
                print(f"  • {test['test']}: {test['message']}")
                if test['details']:
                    print(f"    Details: {test['details']}")
        
        # List passed tests
        passed_tests = [result for result in self.test_results if result['success']]
        if passed_tests:
            print("\n✅ WORKING COMPONENTS:")
            for test in passed_tests:
                print(f"  • {test['test']}: {test['message']}")
        
        return passed == total

def main():
    """Main test execution"""
    tester = CriticalErrorResolutionTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! No critical errors found.")
        sys.exit(0)
    else:
        print("\n⚠️  CRITICAL SYSTEM MISMATCH DETECTED!")
        print("The user expects a PHP-based invoice management system,")
        print("but the current system is FastAPI + React.")
        print("PHP files exist but are not being served.")
        sys.exit(1)

if __name__ == "__main__":
    main()