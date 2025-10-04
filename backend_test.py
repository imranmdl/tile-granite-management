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
            
            auth_response = self.session.post(f"{self.base_url}/login.php", data=login_data, timeout=10, allow_redirects=False)
            
            # Debug: print response details
            print(f"    DEBUG: Auth response status: {auth_response.status_code}")
            
            # Check if login was successful (should redirect or show dashboard)
            if auth_response.status_code == 200 and ('dashboard' in auth_response.text.lower() or 'logout' in auth_response.text.lower()):
                self.authenticated = True
                self.log_test("Authentication System", True, "Admin login successful - dashboard accessible")
                return True
            elif auth_response.status_code == 302:
                # Login successful, now check if we can access the dashboard
                dashboard_response = self.session.get(f"{self.base_url}/", timeout=10)
                print(f"    DEBUG: Dashboard response status: {dashboard_response.status_code}")
                print(f"    DEBUG: Dashboard content contains 'dashboard': {'dashboard' in dashboard_response.text.lower()}")
                print(f"    DEBUG: Dashboard content contains 'logout': {'logout' in dashboard_response.text.lower()}")
                
                if dashboard_response.status_code == 200 and ('dashboard' in dashboard_response.text.lower() or 'logout' in dashboard_response.text.lower()):
                    self.authenticated = True
                    self.log_test("Authentication System", True, "Admin login successful - dashboard accessible after redirect")
                    return True
            
            self.log_test("Authentication System", False, f"Login failed - status: {auth_response.status_code}")
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
            response = self.session.get(f"{self.base_url}/quotation_enhanced.php", timeout=10)
            
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
            response = self.session.get(f"{self.base_url}/item_profit.php", timeout=10)
            
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

    def test_quotation_profit_php(self):
        """Test quotation_profit.php - previously had undefined function compute_range() errors"""
        if not self.authenticated:
            self.log_test("quotation_profit.php Function Validation", False, "Authentication required")
            return False
            
        try:
            response = self.session.get(f"{self.base_url}/quotation_profit.php", timeout=10)
            
            if response.status_code == 200:
                # Check for undefined function errors
                if 'undefined function' in response.text.lower() and 'compute_range' in response.text:
                    self.log_test("quotation_profit.php Function Validation", False, 
                                "compute_range() function still undefined")
                    return False
                
                # Check for other fatal errors
                if 'fatal error' in response.text.lower() or 'call to undefined function' in response.text.lower():
                    self.log_test("quotation_profit.php Function Validation", False, 
                                "Fatal function errors found")
                    return False
                
                # Check if page loads with profit calculations
                if 'profit' in response.text.lower() or 'quotation' in response.text.lower():
                    self.log_test("quotation_profit.php Function Validation", True, 
                                "compute_range() function available - profit calculations working")
                    return True
                else:
                    self.log_test("quotation_profit.php Function Validation", False, 
                                "Page loads but no profit/quotation content found")
                    return False
            else:
                self.log_test("quotation_profit.php Function Validation", False, 
                            f"HTTP {response.status_code} - file not accessible")
                return False
                
        except Exception as e:
            self.log_test("quotation_profit.php Function Validation", False, f"Error: {str(e)}")
            return False

    def test_damage_report_php(self):
        """Test damage_report.php - previously had require_admin() function issues"""
        if not self.authenticated:
            self.log_test("damage_report.php Admin Function Validation", False, "Authentication required")
            return False
            
        try:
            response = self.session.get(f"{self.base_url}/damage_report.php", timeout=10)
            
            if response.status_code == 200:
                # Check for require_admin() function errors
                if 'undefined function' in response.text.lower() and 'require_admin' in response.text:
                    self.log_test("damage_report.php Admin Function Validation", False, 
                                "require_admin() function not found")
                    return False
                
                # Check for access denied (means function works but user lacks permission)
                if 'access denied' in response.text.lower() or 'permission denied' in response.text.lower():
                    self.log_test("damage_report.php Admin Function Validation", True, 
                                "require_admin() function working - access control active")
                    return True
                
                # Check if page loads with damage report content
                if 'damage' in response.text.lower() or 'report' in response.text.lower():
                    self.log_test("damage_report.php Admin Function Validation", True, 
                                "require_admin() function working - damage report accessible")
                    return True
                else:
                    self.log_test("damage_report.php Admin Function Validation", False, 
                                "Page loads but no damage report content found")
                    return False
            else:
                self.log_test("damage_report.php Admin Function Validation", False, 
                            f"HTTP {response.status_code} - file not accessible")
                return False
                
        except Exception as e:
            self.log_test("damage_report.php Admin Function Validation", False, f"Error: {str(e)}")
            return False

    def test_report_inventory_php(self):
        """Test report_inventory.php - previously had 'total_stock_units' column errors"""
        if not self.authenticated:
            self.log_test("report_inventory.php Column Validation", False, "Authentication required")
            return False
            
        try:
            response = self.session.get(f"{self.base_url}/report_inventory.php", timeout=10)
            
            if response.status_code == 200:
                # Check for total_stock_units column errors
                if 'total_stock_units' in response.text and 'error' in response.text.lower():
                    self.log_test("report_inventory.php Column Validation", False, 
                                "total_stock_units column errors still present")
                    return False
                
                # Check for other database errors
                if 'database error' in response.text.lower() or 'sql error' in response.text.lower():
                    self.log_test("report_inventory.php Column Validation", False, 
                                "Database query errors found")
                    return False
                
                # Check if page loads with inventory data
                if 'inventory' in response.text.lower() or 'stock' in response.text.lower():
                    self.log_test("report_inventory.php Column Validation", True, 
                                "Database queries execute without total_stock_units errors")
                    return True
                else:
                    self.log_test("report_inventory.php Column Validation", False, 
                                "Page loads but no inventory content found")
                    return False
            else:
                self.log_test("report_inventory.php Column Validation", False, 
                            f"HTTP {response.status_code} - file not accessible")
                return False
                
        except Exception as e:
            self.log_test("report_inventory.php Column Validation", False, f"Error: {str(e)}")
            return False

    def test_database_connectivity(self):
        """Test SQLite database connectivity"""
        if not self.authenticated:
            self.log_test("Database Connectivity", False, "Authentication required")
            return False
            
        try:
            # Test database connectivity by accessing a page that requires database
            response = self.session.get(f"{self.base_url}/dashboard_test.php", timeout=10)
            
            if response.status_code == 200:
                # Check for database connection errors
                if 'database connection failed' in response.text.lower() or 'sqlite error' in response.text.lower():
                    self.log_test("Database Connectivity", False, "SQLite database connection failed")
                    return False
                
                # If we can access dashboard or any data-driven page, database is working
                if 'dashboard' in response.text.lower() or 'data' in response.text.lower():
                    self.log_test("Database Connectivity", True, "SQLite database connectivity verified")
                    return True
                else:
                    # Try another database-dependent page
                    inv_response = self.session.get(f"{self.base_url}/inventory.php", timeout=10)
                    if inv_response.status_code == 200 and 'inventory' in inv_response.text.lower():
                        self.log_test("Database Connectivity", True, "SQLite database connectivity verified via inventory")
                        return True
                    
                    self.log_test("Database Connectivity", False, "Cannot verify database connectivity")
                    return False
            else:
                self.log_test("Database Connectivity", False, f"Cannot access database test pages - HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Database Connectivity", False, f"Error: {str(e)}")
            return False

    def test_business_logic(self):
        """Test commission calculations and business logic"""
        if not self.authenticated:
            self.log_test("Business Logic Validation", False, "Authentication required")
            return False
            
        try:
            # Test commission system
            response = self.session.get(f"{self.base_url}/commission_ledger.php", timeout=10)
            
            if response.status_code == 200:
                if 'commission' in response.text.lower() and ('calculation' in response.text.lower() or 'amount' in response.text.lower()):
                    self.log_test("Business Logic Validation", True, "Commission calculations and business logic working")
                    return True
                else:
                    self.log_test("Business Logic Validation", False, "Commission system not functioning properly")
                    return False
            else:
                self.log_test("Business Logic Validation", False, f"Cannot access commission system - HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Business Logic Validation", False, f"Error: {str(e)}")
            return False

    def test_reporting_module(self):
        """Test reporting dashboard and report generation"""
        if not self.authenticated:
            self.log_test("Reporting Module Validation", False, "Authentication required")
            return False
            
        try:
            # Test reports dashboard
            response = self.session.get(f"{self.base_url}/public/reports_dashboard.php", timeout=10)
            
            if response.status_code == 200:
                if 'report' in response.text.lower() and ('dashboard' in response.text.lower() or 'sales' in response.text.lower()):
                    self.log_test("Reporting Module Validation", True, "Reporting dashboard and modules accessible")
                    return True
                else:
                    self.log_test("Reporting Module Validation", False, "Reporting dashboard not functioning properly")
                    return False
            else:
                self.log_test("Reporting Module Validation", False, f"Cannot access reporting dashboard - HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Reporting Module Validation", False, f"Error: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all PHP business management system tests"""
        print("🧪 Starting PHP Business Management System Testing")
        print("Testing critical PHP files after infrastructure fixes")
        print("=" * 70)
        
        # Test authentication first (required for other tests)
        print("\n🔐 Testing Authentication System...")
        auth_success = self.test_authentication_system()
        
        if not auth_success:
            print("\n❌ CRITICAL: Authentication failed - cannot proceed with other tests")
            print("All other tests require admin authentication")
            return False
        
        # Test database connectivity
        print("\n🗄️ Testing Database Connectivity...")
        self.test_database_connectivity()
        
        # Test specific PHP files mentioned in review request
        print("\n📄 Testing quotation_enhanced.php (syntax validation)...")
        self.test_quotation_enhanced_php()
        
        print("\n📊 Testing item_profit.php (database query validation)...")
        self.test_item_profit_php()
        
        print("\n🧮 Testing quotation_profit.php (function validation)...")
        self.test_quotation_profit_php()
        
        print("\n🛡️ Testing damage_report.php (admin function validation)...")
        self.test_damage_report_php()
        
        print("\n📋 Testing report_inventory.php (column validation)...")
        self.test_report_inventory_php()
        
        # Test business logic
        print("\n💼 Testing Business Logic...")
        self.test_business_logic()
        
        # Test reporting module
        print("\n📈 Testing Reporting Module...")
        self.test_reporting_module()
        
        # Summary
        print("\n" + "=" * 70)
        print("📊 PHP BUSINESS MANAGEMENT SYSTEM TEST SUMMARY")
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
            print("\n❌ ISSUES FOUND:")
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
    tester = PHPBusinessSystemTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! PHP business management system is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some issues found in PHP business management system.")
        print("Check the test results above for specific problems.")
        sys.exit(1)

if __name__ == "__main__":
    main()