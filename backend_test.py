#!/usr/bin/env python3
"""
Backend Test Suite for Commission and Reporting System
Tests the comprehensive commission and reporting system implementation.
"""

import requests
import json
import time
from urllib.parse import urljoin, urlparse, parse_qs
import re
import sys
import os
import sqlite3
from datetime import datetime

class CommissionReportingSystemTester:
    def __init__(self, base_url="https://tilecrm-app.preview.emergentagent.com"):
        self.base_url = base_url
        self.php_url = f"{base_url}/public"
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        })
        self.test_results = []
        self.db_path = '/app/data/app.sqlite'
        
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

    def test_database_schema_validation(self):
        """Test that all required database tables and columns exist"""
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Check required tables
            required_tables = [
                'commission_records', 'users_simple', 'user_report_preferences', 
                'report_cache', 'cost_history', 'commission_ledger', 'commission_rates'
            ]
            
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table'")
            existing_tables = [row[0] for row in cursor.fetchall()]
            
            missing_tables = []
            for table in required_tables:
                if table not in existing_tables:
                    missing_tables.append(table)
            
            if missing_tables:
                self.log_test("Database Schema Validation", False, f"Missing tables: {missing_tables}")
                conn.close()
                return False
            
            # Check users_simple has permission columns
            cursor.execute("PRAGMA table_info(users_simple)")
            columns = [col[1] for col in cursor.fetchall()]
            required_columns = ['can_view_pl', 'can_view_reports', 'can_export_data']
            
            missing_columns = []
            for col in required_columns:
                if col not in columns:
                    missing_columns.append(col)
            
            if missing_columns:
                self.log_test("Database Schema Validation", False, f"Missing columns in users_simple: {missing_columns}")
                conn.close()
                return False
            
            # Check commission_records structure
            cursor.execute("PRAGMA table_info(commission_records)")
            commission_columns = [col[1] for col in cursor.fetchall()]
            required_commission_cols = ['document_type', 'document_id', 'user_id', 'base_amount', 'commission_percentage', 'commission_amount', 'status']
            
            missing_commission_cols = []
            for col in required_commission_cols:
                if col not in commission_columns:
                    missing_commission_cols.append(col)
            
            if missing_commission_cols:
                self.log_test("Database Schema Validation", False, f"Missing columns in commission_records: {missing_commission_cols}")
                conn.close()
                return False
            
            conn.close()
            self.log_test("Database Schema Validation", True, "All required tables and columns exist")
            return True
            
        except Exception as e:
            self.log_test("Database Schema Validation", False, f"Error: {str(e)}")
            return False

    def test_commission_system(self):
        """Test commission application functionality"""
        try:
            # Check if commission system is accessible
            response = self.session.get(f"{self.php_url}/commission_settings.php", timeout=10)
            
            if response.status_code != 200:
                self.log_test("Commission System", False, f"Commission settings page not accessible: HTTP {response.status_code}")
                return False
            
            # Check database for commission data
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Check commission rates
            cursor.execute("SELECT COUNT(*) FROM commission_rates")
            rates_count = cursor.fetchone()[0]
            
            # Check commission ledger
            cursor.execute("SELECT COUNT(*) FROM commission_ledger")
            ledger_count = cursor.fetchone()[0]
            
            conn.close()
            
            if rates_count > 0 or ledger_count > 0:
                self.log_test("Commission System", True, f"Commission system functional with {rates_count} rates and {ledger_count} ledger entries")
                return True
            else:
                self.log_test("Commission System", True, "Commission system accessible but no data yet (expected for new system)")
                return True
                
        except Exception as e:
            self.log_test("Commission System", False, f"Error: {str(e)}")
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