#!/usr/bin/env python3
"""
Backend Test Suite for Critical Error Resolution Testing
Tests the actual system implementation and identifies critical mismatches.
"""

import requests
import json
import sys
from datetime import datetime

class CriticalErrorResolutionTester:
    def __init__(self, base_url="https://tile-mgmt-system.preview.emergentagent.com"):
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

    def test_system_architecture(self):
        """Test what system is actually running"""
        try:
            # Test FastAPI backend
            response = self.session.get(f"{self.api_url}/", timeout=10)
            if response.status_code == 200:
                data = response.json()
                if data.get('message') == 'Hello World':
                    self.log_test("System Architecture", False, 
                                "CRITICAL MISMATCH: FastAPI system running, but user expects PHP system",
                                "Current system: FastAPI + React. User requested testing of PHP files (quotation_enhanced.php, item_profit.php, etc.) but these are not served by the current system.")
                    return False
            
            self.log_test("System Architecture", False, "Unable to determine system type")
            return False
            
        except Exception as e:
            self.log_test("System Architecture", False, f"Error: {str(e)}")
            return False

    def test_php_file_accessibility(self):
        """Test if PHP files are accessible via web"""
        php_files = [
            'quotation_enhanced.php',
            'item_profit.php', 
            'quotation_profit.php',
            'damage_report.php',
            'report_inventory.php'
        ]
        
        accessible_files = []
        for php_file in php_files:
            try:
                # Try direct access
                response = self.session.get(f"{self.base_url}/{php_file}", timeout=5)
                if response.status_code == 200 and 'php' in response.text.lower():
                    accessible_files.append(php_file)
                    
                # Try public folder access
                response = self.session.get(f"{self.base_url}/public/{php_file}", timeout=5)
                if response.status_code == 200 and 'php' in response.text.lower():
                    accessible_files.append(f"public/{php_file}")
                    
            except:
                pass
        
        if accessible_files:
            self.log_test("PHP File Accessibility", True, f"PHP files accessible: {', '.join(accessible_files)}")
            return True
        else:
            self.log_test("PHP File Accessibility", False, 
                        "CRITICAL: No PHP files accessible via web",
                        "All PHP file requests return React app HTML instead of PHP execution. React frontend is intercepting all requests.")
            return False

    def test_authentication_system(self):
        """Test if admin/admin123 authentication works"""
        try:
            # Try to access login endpoint
            login_response = self.session.get(f"{self.base_url}/login.php", timeout=5)
            if login_response.status_code == 200 and 'login' in login_response.text.lower():
                # Try authentication
                login_data = {
                    'username': 'admin',
                    'password': 'admin123'
                }
                auth_response = self.session.post(f"{self.base_url}/login.php", data=login_data, timeout=5)
                if auth_response.status_code == 200:
                    self.log_test("Authentication System", True, "PHP authentication system accessible")
                    return True
            
            self.log_test("Authentication System", False, 
                        "PHP authentication not accessible",
                        "Cannot access login.php - React frontend intercepting requests")
            return False
            
        except Exception as e:
            self.log_test("Authentication System", False, f"Error: {str(e)}")
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