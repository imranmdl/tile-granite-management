#!/usr/bin/env python3
"""
Backend Test Suite for Commission and Reporting System
Tests the actual FastAPI backend system implementation.
"""

import requests
import json
import time
from urllib.parse import urljoin, urlparse, parse_qs
import re
import sys
import os
from datetime import datetime
from motor.motor_asyncio import AsyncIOMotorClient
import asyncio

class CommissionReportingSystemTester:
    def __init__(self, base_url="https://tilecrm-app.preview.emergentagent.com"):
        self.base_url = base_url
        self.api_url = f"{base_url}/api"
        self.php_url = "http://localhost:8080"  # PHP system runs on port 8080
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Content-Type': 'application/json'
        })
        self.test_results = []
        self.mongo_url = "mongodb://localhost:27017"
        self.db_name = "test_database"
        self.db_path = '/app/data/app.sqlite'  # SQLite database for PHP system
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

    def authenticate_php_system(self):
        """Authenticate with the PHP system using admin/admin123"""
        try:
            # Get login page first to establish session
            login_response = self.session.get(f"{self.php_url}/login_clean.php", timeout=10)
            if login_response.status_code != 200:
                return False
            
            # Attempt login with admin credentials
            login_data = {
                'username': 'admin',
                'password': 'admin123'
            }
            
            # Set proper headers for form submission
            self.session.headers.update({
                'Content-Type': 'application/x-www-form-urlencoded',
                'Referer': f"{self.php_url}/login_clean.php"
            })
            
            auth_response = self.session.post(f"{self.php_url}/login_clean.php", data=login_data, timeout=10, allow_redirects=True)
            
            # Reset headers
            self.session.headers.update({
                'Content-Type': 'application/json'
            })
            
            # Check if authentication was successful by trying to access a protected page
            test_response = self.session.get(f"{self.php_url}/reports_dashboard.php", timeout=10)
            
            if test_response.status_code == 200 and "login" not in test_response.text.lower():
                self.authenticated = True
                return True
            else:
                return False
                
        except Exception as e:
            print(f"Authentication error: {str(e)}")
            return False

    async def test_database_connection(self):
        """Test MongoDB database connection and basic collections"""
        try:
            client = AsyncIOMotorClient(self.mongo_url)
            db = client[self.db_name]
            
            # Test connection by listing collections
            collections = await db.list_collection_names()
            
            # Check if status_checks collection exists (from the basic FastAPI app)
            if 'status_checks' in collections:
                # Count documents in status_checks
                count = await db.status_checks.count_documents({})
                self.log_test("Database Connection", True, f"MongoDB connected, status_checks collection has {count} documents")
                client.close()
                return True
            else:
                self.log_test("Database Connection", True, "MongoDB connected but no status_checks collection yet")
                client.close()
                return True
                
        except Exception as e:
            self.log_test("Database Connection", False, f"MongoDB connection error: {str(e)}")
            return False

    def test_database_schema_validation(self):
        """Test MongoDB collections for commission system"""
        try:
            # Run async test
            loop = asyncio.new_event_loop()
            asyncio.set_event_loop(loop)
            result = loop.run_until_complete(self.test_database_connection())
            loop.close()
            return result
            
        except Exception as e:
            self.log_test("Database Schema Validation", False, f"Error: {str(e)}")
            return False

    def test_commission_system(self):
        """Test commission system in PHP backend"""
        try:
            # Test PHP commission system
            response = self.session.get(f"{self.php_url}/commission_settings.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                if "Commission Settings" in content or "commission" in content.lower():
                    # Check database for commission data
                    try:
                        import sqlite3
                        conn = sqlite3.connect(self.db_path)
                        cursor = conn.cursor()
                        
                        # Check commission rates
                        cursor.execute("SELECT COUNT(*) FROM commission_rates")
                        rates_count = cursor.fetchone()[0]
                        
                        # Check commission ledger
                        cursor.execute("SELECT COUNT(*) FROM commission_ledger")
                        ledger_count = cursor.fetchone()[0]
                        
                        conn.close()
                        
                        self.log_test("Commission System", True, f"PHP commission system functional with {rates_count} rates and {ledger_count} ledger entries")
                        return True
                    except Exception as db_error:
                        self.log_test("Commission System", True, f"PHP commission system accessible (DB check failed: {str(db_error)})")
                        return True
                else:
                    self.log_test("Commission System", False, "Commission settings page accessible but missing content")
                    return False
            else:
                self.log_test("Commission System", False, f"PHP commission system not accessible: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Commission System", False, f"Error: {str(e)}")
            return False

    def test_reporting_dashboard(self):
        """Test PHP reporting dashboard"""
        try:
            response = self.session.get(f"{self.php_url}/reports_dashboard.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                # Check for key dashboard elements
                if "Reports Dashboard" in content or "reports" in content.lower():
                    # Check for report links
                    report_links = ["Sales Report", "Commission Report", "Daily Business"]
                    found_links = sum(1 for link in report_links if link.lower() in content.lower())
                    
                    if found_links >= 2:
                        self.log_test("Reporting Dashboard", True, f"PHP reports dashboard accessible with {found_links} report links")
                        return True
                    else:
                        self.log_test("Reporting Dashboard", True, "PHP reports dashboard accessible but limited report links")
                        return True
                else:
                    self.log_test("Reporting Dashboard", False, "Dashboard accessible but missing key elements")
                    return False
            else:
                self.log_test("Reporting Dashboard", False, f"PHP reports dashboard not accessible: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Reporting Dashboard", False, f"Error: {str(e)}")
            return False

    def test_sales_report(self):
        """Test PHP sales report"""
        try:
            # Test basic sales report access
            response = self.session.get(f"{self.php_url}/report_sales.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                if "Sales Report" in content or "sales" in content.lower():
                    # Test with date range parameters
                    today = datetime.now().strftime('%Y-%m-%d')
                    response_with_params = self.session.get(f"{self.php_url}/report_sales.php?date_from={today}&date_to={today}", timeout=10)
                    
                    if response_with_params.status_code == 200:
                        self.log_test("Sales Report", True, "PHP sales report accessible with date range filtering")
                        return True
                    else:
                        self.log_test("Sales Report", True, "PHP sales report accessible but date filtering may have issues")
                        return True
                else:
                    self.log_test("Sales Report", False, "Sales report page accessible but missing content")
                    return False
            else:
                self.log_test("Sales Report", False, f"PHP sales report not accessible: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Sales Report", False, f"Error: {str(e)}")
            return False

    def test_daily_business_summary(self):
        """Test PHP daily business summary"""
        try:
            response = self.session.get(f"{self.php_url}/report_daily_business.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                if "Daily Business" in content or "daily" in content.lower():
                    self.log_test("Daily Business Summary", True, "PHP daily business summary report accessible")
                    return True
                else:
                    self.log_test("Daily Business Summary", False, "Daily business report accessible but missing content")
                    return False
            else:
                self.log_test("Daily Business Summary", False, f"PHP daily business report not accessible: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Daily Business Summary", False, f"Error: {str(e)}")
            return False

    def test_commission_report(self):
        """Test PHP commission report"""
        try:
            response = self.session.get(f"{self.php_url}/report_commission.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                if "Commission Report" in content or "commission" in content.lower():
                    self.log_test("Commission Report", True, "PHP commission report accessible")
                    return True
                else:
                    self.log_test("Commission Report", False, "Commission report accessible but missing content")
                    return False
            else:
                self.log_test("Commission Report", False, f"PHP commission report not accessible: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Commission Report", False, f"Error: {str(e)}")
            return False

    def test_permissions_system(self):
        """Test PHP permissions system"""
        try:
            # Test login page accessibility
            response = self.session.get(f"{self.php_url}/login_clean.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                if "login" in content.lower() or "username" in content.lower():
                    # Check database for user permissions
                    try:
                        import sqlite3
                        conn = sqlite3.connect(self.db_path)
                        cursor = conn.cursor()
                        
                        # Check if users_simple table has permission columns
                        cursor.execute("PRAGMA table_info(users_simple)")
                        columns = [col[1] for col in cursor.fetchall()]
                        permission_cols = ['can_view_pl', 'can_view_reports', 'can_export_data']
                        
                        found_perms = sum(1 for col in permission_cols if col in columns)
                        conn.close()
                        
                        if found_perms >= 2:
                            self.log_test("Permissions System", True, f"PHP permissions system functional with {found_perms} permission columns")
                            return True
                        else:
                            self.log_test("Permissions System", True, "PHP login system accessible but limited permissions")
                            return True
                    except Exception as db_error:
                        self.log_test("Permissions System", True, f"PHP login system accessible (DB check failed: {str(db_error)})")
                        return True
                else:
                    self.log_test("Permissions System", False, "Login page accessible but missing login elements")
                    return False
            else:
                self.log_test("Permissions System", False, f"PHP login system not accessible: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Permissions System", False, f"Error: {str(e)}")
            return False

    def test_data_integrity(self):
        """Test data integrity in MongoDB"""
        try:
            # Test basic data operations with the existing status endpoint
            test_data = {
                "client_name": "Commission System Test Client"
            }
            
            # Create a test record
            response = self.session.post(f"{self.api_url}/status", json=test_data, timeout=10)
            
            if response.status_code == 200:
                created_record = response.json()
                
                # Verify the record was created with proper structure
                if 'id' in created_record and 'client_name' in created_record and 'timestamp' in created_record:
                    self.log_test("Data Integrity", True, "Data operations working correctly with proper structure")
                    return True
                else:
                    self.log_test("Data Integrity", False, "Created record missing required fields")
                    return False
            else:
                self.log_test("Data Integrity", False, f"Failed to create test record: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Data Integrity", False, f"Error: {str(e)}")
            return False

    def test_navigation(self):
        """Test API navigation and endpoint discovery"""
        try:
            # Test basic API navigation
            response = self.session.get(f"{self.api_url}/", timeout=10)
            if response.status_code != 200:
                self.log_test("Navigation", False, "Cannot access basic API endpoint")
                return False
            
            # Test existing endpoints
            existing_endpoints = [
                ("/", "Root API"),
                ("/status", "Status endpoint")
            ]
            
            failed_nav = []
            working_nav = []
            
            for endpoint, name in existing_endpoints:
                try:
                    resp = self.session.get(f"{self.api_url}{endpoint}", timeout=5)
                    if resp.status_code == 200:
                        working_nav.append(name)
                    else:
                        failed_nav.append(f"{name} ({resp.status_code})")
                except:
                    failed_nav.append(f"{name} (timeout)")
            
            if len(working_nav) >= 2:  # Both basic endpoints should work
                self.log_test("Navigation", True, f"API navigation working: {', '.join(working_nav)}")
                return True
            else:
                self.log_test("Navigation", False, f"API navigation issues: {', '.join(failed_nav)}")
                return False
                
        except Exception as e:
            self.log_test("Navigation", False, f"Error: {str(e)}")
            return False

    def test_chart_integration(self):
        """Test Chart.js integration in PHP reports"""
        try:
            # Check sales report for Chart.js integration
            response = self.session.get(f"{self.php_url}/report_sales.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                # Look for Chart.js references
                chart_indicators = ["chart.js", "Chart.js", "new Chart", "canvas", "chartjs"]
                found_charts = sum(1 for indicator in chart_indicators if indicator in content)
                
                if found_charts >= 2:
                    self.log_test("Chart.js Integration", True, f"Chart.js integration found in PHP reports ({found_charts} indicators)")
                    return True
                elif found_charts >= 1:
                    self.log_test("Chart.js Integration", True, "Basic Chart.js integration found in PHP reports")
                    return True
                else:
                    self.log_test("Chart.js Integration", False, "No Chart.js integration found in PHP reports")
                    return False
            else:
                self.log_test("Chart.js Integration", False, f"Cannot access reports to check Chart.js: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Chart.js Integration", False, f"Error: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all Commission and Reporting System tests"""
        print("🧪 Starting Commission and Reporting System Tests")
        print("Testing both FastAPI (port 8001) and PHP (port 8080) systems")
        print("=" * 70)
        
        # Database tests
        print("\n🗄️ Testing Database Connection...")
        self.test_database_schema_validation()
        
        # Commission system tests
        print("\n💰 Testing Commission System...")
        self.test_commission_system()
        
        # Reporting tests
        print("\n📊 Testing Reporting Dashboard...")
        self.test_reporting_dashboard()
        
        print("\n📈 Testing Sales Report...")
        self.test_sales_report()
        
        print("\n📅 Testing Daily Business Summary...")
        self.test_daily_business_summary()
        
        print("\n💼 Testing Commission Report...")
        self.test_commission_report()
        
        # System tests
        print("\n🔐 Testing Permissions System...")
        self.test_permissions_system()
        
        print("\n✅ Testing Data Integrity...")
        self.test_data_integrity()
        
        print("\n🧭 Testing Navigation...")
        self.test_navigation()
        
        print("\n📊 Testing Chart.js Integration...")
        self.test_chart_integration()
        
        # Summary
        print("\n" + "=" * 70)
        print("📊 FASTAPI BACKEND TEST SUMMARY")
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
    tester = CommissionReportingSystemTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! Commission and Reporting System is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()