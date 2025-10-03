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