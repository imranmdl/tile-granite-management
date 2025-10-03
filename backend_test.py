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
        """Test sales report endpoints in FastAPI"""
        try:
            # Check for sales report endpoints
            sales_endpoints = [
                "/reports/sales",
                "/sales/summary",
                "/sales/data"
            ]
            
            sales_found = False
            for endpoint in sales_endpoints:
                try:
                    resp = self.session.get(f"{self.api_url}{endpoint}", timeout=5)
                    if resp.status_code != 404:
                        sales_found = True
                        break
                except:
                    continue
            
            if sales_found:
                self.log_test("Sales Report", True, "Sales report endpoints found in FastAPI")
                return True
            else:
                self.log_test("Sales Report", False, "No sales report endpoints implemented in FastAPI backend")
                return False
                
        except Exception as e:
            self.log_test("Sales Report", False, f"Error: {str(e)}")
            return False

    def test_daily_business_summary(self):
        """Test daily business summary endpoints in FastAPI"""
        try:
            # Check for daily business endpoints
            daily_endpoints = [
                "/reports/daily",
                "/business/daily",
                "/reports/daily-summary"
            ]
            
            daily_found = False
            for endpoint in daily_endpoints:
                try:
                    resp = self.session.get(f"{self.api_url}{endpoint}", timeout=5)
                    if resp.status_code != 404:
                        daily_found = True
                        break
                except:
                    continue
            
            if daily_found:
                self.log_test("Daily Business Summary", True, "Daily business endpoints found in FastAPI")
                return True
            else:
                self.log_test("Daily Business Summary", False, "No daily business endpoints implemented in FastAPI backend")
                return False
                
        except Exception as e:
            self.log_test("Daily Business Summary", False, f"Error: {str(e)}")
            return False

    def test_commission_report(self):
        """Test commission report endpoints in FastAPI"""
        try:
            # Check for commission report endpoints
            commission_report_endpoints = [
                "/reports/commission",
                "/commission/report",
                "/commission/summary"
            ]
            
            commission_report_found = False
            for endpoint in commission_report_endpoints:
                try:
                    resp = self.session.get(f"{self.api_url}{endpoint}", timeout=5)
                    if resp.status_code != 404:
                        commission_report_found = True
                        break
                except:
                    continue
            
            if commission_report_found:
                self.log_test("Commission Report", True, "Commission report endpoints found in FastAPI")
                return True
            else:
                self.log_test("Commission Report", False, "No commission report endpoints implemented in FastAPI backend")
                return False
                
        except Exception as e:
            self.log_test("Commission Report", False, f"Error: {str(e)}")
            return False

    def test_permissions_system(self):
        """Test permissions system in FastAPI"""
        try:
            # Check for authentication/permissions endpoints
            auth_endpoints = [
                "/auth/login",
                "/auth/permissions",
                "/users/permissions"
            ]
            
            auth_found = False
            for endpoint in auth_endpoints:
                try:
                    resp = self.session.get(f"{self.api_url}{endpoint}", timeout=5)
                    if resp.status_code != 404:
                        auth_found = True
                        break
                except:
                    continue
            
            if auth_found:
                self.log_test("Permissions System", True, "Authentication/permissions endpoints found in FastAPI")
                return True
            else:
                self.log_test("Permissions System", False, "No authentication/permissions endpoints implemented in FastAPI backend")
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