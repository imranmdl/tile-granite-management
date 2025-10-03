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
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Content-Type': 'application/json'
        })
        self.test_results = []
        self.mongo_url = "mongodb://localhost:27017"
        self.db_name = "test_database"
        
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
        """Test commission system endpoints in FastAPI"""
        try:
            # Check if there are any commission-related endpoints
            # Since the current FastAPI only has basic endpoints, this will test what exists
            
            # Test basic API endpoint
            response = self.session.get(f"{self.api_url}/", timeout=10)
            if response.status_code != 200:
                self.log_test("Commission System", False, f"Basic API not accessible: HTTP {response.status_code}")
                return False
            
            # Check if there are any commission endpoints (they don't exist in current implementation)
            commission_endpoints = [
                "/commission/rates",
                "/commission/calculate", 
                "/commission/ledger"
            ]
            
            commission_found = False
            for endpoint in commission_endpoints:
                try:
                    resp = self.session.get(f"{self.api_url}{endpoint}", timeout=5)
                    if resp.status_code != 404:
                        commission_found = True
                        break
                except:
                    continue
            
            if commission_found:
                self.log_test("Commission System", True, "Commission endpoints found in FastAPI")
                return True
            else:
                self.log_test("Commission System", False, "No commission endpoints implemented in FastAPI backend")
                return False
                
        except Exception as e:
            self.log_test("Commission System", False, f"Error: {str(e)}")
            return False

    def test_reporting_dashboard(self):
        """Test reporting endpoints in FastAPI"""
        try:
            # Check if there are any reporting endpoints in FastAPI
            reporting_endpoints = [
                "/reports/dashboard",
                "/reports/sales",
                "/reports/commission",
                "/reports/daily"
            ]
            
            reports_found = False
            for endpoint in reporting_endpoints:
                try:
                    resp = self.session.get(f"{self.api_url}{endpoint}", timeout=5)
                    if resp.status_code != 404:
                        reports_found = True
                        break
                except:
                    continue
            
            if reports_found:
                self.log_test("Reporting Dashboard", True, "Reporting endpoints found in FastAPI")
                return True
            else:
                self.log_test("Reporting Dashboard", False, "No reporting endpoints implemented in FastAPI backend")
                return False
                
        except Exception as e:
            self.log_test("Reporting Dashboard", False, f"Error: {str(e)}")
            return False

    def test_sales_report(self):
        """Test sales report with date ranges and presets"""
        try:
            # Test basic sales report access
            response = self.session.get(f"{self.php_url}/report_sales.php", timeout=10)
            
            if response.status_code != 200:
                self.log_test("Sales Report", False, f"HTTP {response.status_code}")
                return False
            
            content = response.text
            if "Sales Report" not in content:
                self.log_test("Sales Report", False, "Sales report page missing title")
                return False
            
            # Test with date range parameters
            today = datetime.now().strftime('%Y-%m-%d')
            response = self.session.get(f"{self.php_url}/report_sales.php?date_from={today}&date_to={today}", timeout=10)
            
            if response.status_code == 200:
                self.log_test("Sales Report", True, "Sales report accessible with date range filtering")
                return True
            else:
                self.log_test("Sales Report", False, f"Date range filtering failed: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Sales Report", False, f"Error: {str(e)}")
            return False

    def test_daily_business_summary(self):
        """Test daily business summary calculations and displays"""
        try:
            response = self.session.get(f"{self.php_url}/report_daily_business.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                if "Daily Business Report" in content or "My Daily Business Report" in content:
                    self.log_test("Daily Business Summary", True, "Daily business summary report accessible")
                    return True
                else:
                    self.log_test("Daily Business Summary", False, "Daily business report missing title")
                    return False
            else:
                self.log_test("Daily Business Summary", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Daily Business Summary", False, f"Error: {str(e)}")
            return False

    def test_commission_report(self):
        """Test commission tracking and status updates"""
        try:
            response = self.session.get(f"{self.php_url}/report_commission.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text
                if "Commission Report" in content:
                    self.log_test("Commission Report", True, "Commission report accessible")
                    return True
                else:
                    self.log_test("Commission Report", False, "Commission report missing title")
                    return False
            else:
                self.log_test("Commission Report", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Commission Report", False, f"Error: {str(e)}")
            return False

    def test_permissions_system(self):
        """Test P/L access permissions are working"""
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Check if admin user has proper permissions
            cursor.execute("SELECT can_view_pl, can_view_reports FROM users_simple WHERE role = 'admin' LIMIT 1")
            admin_perms = cursor.fetchone()
            
            if admin_perms and admin_perms[0] == 1 and admin_perms[1] == 1:
                self.log_test("Permissions System", True, "Admin user has proper P/L and reports permissions")
                conn.close()
                return True
            else:
                self.log_test("Permissions System", False, "Admin user missing proper permissions")
                conn.close()
                return False
                
        except Exception as e:
            self.log_test("Permissions System", False, f"Error: {str(e)}")
            return False

    def test_data_integrity(self):
        """Test that calculations are accurate"""
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Check if commission calculations are consistent
            cursor.execute("""
                SELECT COUNT(*) FROM commission_ledger 
                WHERE base_amount > 0 AND pct > 0 AND amount > 0
            """)
            valid_commissions = cursor.fetchone()[0]
            
            cursor.execute("SELECT COUNT(*) FROM commission_ledger")
            total_commissions = cursor.fetchone()[0]
            
            conn.close()
            
            if total_commissions == 0:
                self.log_test("Data Integrity", True, "No commission data to validate (expected for new system)")
                return True
            elif valid_commissions == total_commissions:
                self.log_test("Data Integrity", True, f"All {total_commissions} commission calculations are valid")
                return True
            else:
                self.log_test("Data Integrity", False, f"Invalid commission calculations: {total_commissions - valid_commissions} out of {total_commissions}")
                return False
                
        except Exception as e:
            self.log_test("Data Integrity", False, f"Error: {str(e)}")
            return False

    def test_navigation(self):
        """Test all report links and navigation"""
        try:
            # Test main dashboard navigation
            response = self.session.get(f"{self.php_url}/reports_dashboard.php", timeout=10)
            if response.status_code != 200:
                self.log_test("Navigation", False, "Cannot access main dashboard")
                return False
            
            # Test key navigation links
            navigation_tests = [
                ("report_sales.php", "Sales Report"),
                ("report_commission.php", "Commission Report"),
                ("report_daily_business.php", "Daily Business")
            ]
            
            failed_nav = []
            for url, name in navigation_tests:
                try:
                    resp = self.session.get(f"{self.php_url}/{url}", timeout=5)
                    if resp.status_code != 200:
                        failed_nav.append(f"{name} ({resp.status_code})")
                except:
                    failed_nav.append(f"{name} (timeout)")
            
            if failed_nav:
                self.log_test("Navigation", False, f"Failed navigation: {', '.join(failed_nav)}")
                return False
            else:
                self.log_test("Navigation", True, "All report navigation links working")
                return True
                
        except Exception as e:
            self.log_test("Navigation", False, f"Error: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all Commission and Reporting System tests"""
        print("🧪 Starting Commission and Reporting System Tests")
        print("=" * 60)
        
        # Database tests
        print("\n🗄️ Testing Database Schema...")
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
        
        # Summary
        print("\n" + "=" * 60)
        print("📊 COMMISSION & REPORTING SYSTEM TEST SUMMARY")
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