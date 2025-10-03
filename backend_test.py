#!/usr/bin/env python3
"""
Backend Test Suite for PHP-based Enhanced Inventory Management System
Tests the complete inventory system including tiles, other items, purchase entries, 
QR code generation, cost calculations, and enhanced UI features.
"""

import requests
import json
import time
from urllib.parse import urljoin, urlparse, parse_qs
import re
from bs4 import BeautifulSoup
import sys
import os
from datetime import datetime

class EnhancedInventoryTester:
    def __init__(self, base_url="http://localhost"):
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
        
    def test_login_page_access(self):
        """Test if login page is accessible"""
        try:
            url = f"{self.base_url}/public/login_clean.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                if "Tile Suite" in response.text and "Username" in response.text:
                    self.log_test("Login Page Access", True, "Login page loads successfully")
                    return True
                else:
                    self.log_test("Login Page Access", False, "Login page content missing", response.text[:500])
                    return False
            else:
                self.log_test("Login Page Access", False, f"HTTP {response.status_code}", response.text[:200])
                return False
                
        except Exception as e:
            self.log_test("Login Page Access", False, f"Connection error: {str(e)}")
            return False
    
    def test_login_with_credentials(self, username, password, expected_success=True):
        """Test login with specific credentials"""
        try:
            # Use fresh session for invalid login tests
            if not expected_success:
                test_session = requests.Session()
                test_session.headers.update(self.session.headers)
            else:
                test_session = self.session
                
            # First get the login page to establish session
            login_url = f"{self.base_url}/public/login_clean.php"
            response = test_session.get(login_url)
            
            # Submit login form
            login_data = {
                'username': username,
                'password': password
            }
            
            response = test_session.post(login_url, data=login_data, allow_redirects=False)
            
            if expected_success:
                if response.status_code in [302, 301]:  # Redirect on successful login
                    location = response.headers.get('Location', '')
                    if 'index.php' in location or location.startswith('/public/'):
                        self.log_test(f"Login Test ({username})", True, f"Successful login and redirect to {location}")
                        return True
                    else:
                        self.log_test(f"Login Test ({username})", False, f"Unexpected redirect: {location}")
                        return False
                else:
                    self.log_test(f"Login Test ({username})", False, f"No redirect on login, status: {response.status_code}")
                    return False
            else:
                # For invalid credentials, should stay on login page with error
                if response.status_code == 200:
                    if "Invalid username or password" in response.text:
                        self.log_test(f"Invalid Login Test ({username})", True, "Correctly rejected invalid credentials")
                        return True
                    else:
                        self.log_test(f"Invalid Login Test ({username})", False, "No error message for invalid credentials")
                        return False
                elif response.status_code in [302, 301]:
                    self.log_test(f"Invalid Login Test ({username})", False, "Should not redirect on invalid credentials")
                    return False
                else:
                    self.log_test(f"Invalid Login Test ({username})", False, f"Unexpected status: {response.status_code}")
                    return False
                    
        except Exception as e:
            self.log_test(f"Login Test ({username})", False, f"Error: {str(e)}")
            return False
    
    def test_session_management(self):
        """Test session management and authentication persistence"""
        try:
            # Login first
            if not self.test_login_with_credentials("admin", "admin123"):
                return False
            
            # Try to access protected page
            users_url = f"{self.base_url}/public/users_management.php"
            response = self.session.get(users_url)
            
            if response.status_code == 200:
                if "User Management" in response.text and "Total Users" in response.text:
                    self.log_test("Session Management", True, "Successfully accessed protected page after login")
                    return True
                else:
                    self.log_test("Session Management", False, "Protected page content missing", response.text[:500])
                    return False
            else:
                self.log_test("Session Management", False, f"Cannot access protected page: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Session Management", False, f"Error: {str(e)}")
            return False
    
    def test_users_management_page(self):
        """Test users management page functionality"""
        try:
            # Ensure we're logged in as admin
            self.test_login_with_credentials("admin", "admin123")
            
            users_url = f"{self.base_url}/public/users_management.php"
            response = self.session.get(users_url)
            
            if response.status_code != 200:
                self.log_test("Users Management Page", False, f"HTTP {response.status_code}")
                return False
            
            # Parse the HTML to check for key elements
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Check for statistics cards
            stats_found = len(soup.find_all('div', class_='card-body')) >= 4
            
            # Check for user list
            user_cards = soup.find_all('div', class_='user-card')
            
            # Check for no undefined array key warnings
            has_warnings = "Undefined array key" in response.text or "Warning:" in response.text
            
            if stats_found and len(user_cards) >= 3 and not has_warnings:
                self.log_test("Users Management Page", True, f"Page loads correctly with {len(user_cards)} users, no warnings")
                return True
            else:
                issues = []
                if not stats_found:
                    issues.append("Statistics cards missing")
                if len(user_cards) < 3:
                    issues.append(f"Expected 3+ users, found {len(user_cards)}")
                if has_warnings:
                    issues.append("PHP warnings present")
                
                self.log_test("Users Management Page", False, f"Issues: {', '.join(issues)}")
                return False
                
        except Exception as e:
            self.log_test("Users Management Page", False, f"Error: {str(e)}")
            return False
    
    def test_user_statistics(self):
        """Test user statistics display"""
        try:
            # Ensure we're logged in as admin
            self.test_login_with_credentials("admin", "admin123")
            
            users_url = f"{self.base_url}/public/users_management.php"
            response = self.session.get(users_url)
            
            if response.status_code != 200:
                return False
            
            # Extract statistics from the page
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Find statistics cards
            stats = {}
            card_bodies = soup.find_all('div', class_='card-body')
            
            for card in card_bodies:
                title_elem = card.find('h6', class_='card-title')
                value_elem = card.find('h3')
                
                if title_elem and value_elem:
                    title = title_elem.get_text().strip()
                    value = value_elem.get_text().strip()
                    stats[title] = value
            
            # Validate expected statistics
            expected_stats = ['Total Users', 'Active Users', 'Administrators', 'Inactive Users']
            found_stats = all(stat in stats for stat in expected_stats)
            
            if found_stats:
                stats_summary = ", ".join([f"{k}: {v}" for k, v in stats.items()])
                self.log_test("User Statistics", True, f"All statistics present - {stats_summary}")
                return True
            else:
                missing = [stat for stat in expected_stats if stat not in stats]
                self.log_test("User Statistics", False, f"Missing statistics: {missing}")
                return False
                
        except Exception as e:
            self.log_test("User Statistics", False, f"Error: {str(e)}")
            return False
    
    def test_user_creation(self):
        """Test user creation functionality"""
        try:
            # Ensure we're logged in as admin
            self.test_login_with_credentials("admin", "admin123")
            
            # Generate unique username
            test_username = f"testuser_{int(time.time())}"
            
            users_url = f"{self.base_url}/public/users_management.php"
            
            # Submit user creation form
            user_data = {
                'create_user': '1',
                'username': test_username,
                'password': 'TestPass123!',
                'role': 'sales',
                'name': 'Test User',
                'email': 'test@example.com'
            }
            
            response = self.session.post(users_url, data=user_data)
            
            if response.status_code == 200:
                if "User created successfully" in response.text:
                    self.log_test("User Creation", True, f"Successfully created user: {test_username}")
                    return True
                elif "Username already exists" in response.text:
                    self.log_test("User Creation", False, "Username conflict (expected for repeated tests)")
                    return False
                else:
                    self.log_test("User Creation", False, "No success message found", response.text[:500])
                    return False
            else:
                self.log_test("User Creation", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("User Creation", False, f"Error: {str(e)}")
            return False
    
    def test_form_validation(self):
        """Test form validation for user creation"""
        try:
            # Ensure we're logged in as admin
            self.test_login_with_credentials("admin", "admin123")
            
            users_url = f"{self.base_url}/public/users_management.php"
            
            # Test with empty username
            user_data = {
                'create_user': '1',
                'username': '',
                'password': 'TestPass123!',
                'role': 'sales'
            }
            
            response = self.session.post(users_url, data=user_data)
            
            if "Username and password are required" in response.text:
                self.log_test("Form Validation (Empty Username)", True, "Correctly validates empty username")
            else:
                self.log_test("Form Validation (Empty Username)", False, "Should reject empty username")
                return False
            
            # Test with short password
            user_data = {
                'create_user': '1',
                'username': 'testuser',
                'password': '123',
                'role': 'sales'
            }
            
            response = self.session.post(users_url, data=user_data)
            
            if "Password must be at least 6 characters" in response.text:
                self.log_test("Form Validation (Short Password)", True, "Correctly validates password length")
                return True
            else:
                self.log_test("Form Validation (Short Password)", False, "Should reject short password")
                return False
                
        except Exception as e:
            self.log_test("Form Validation", False, f"Error: {str(e)}")
            return False
    
    def test_role_based_access(self):
        """Test role-based access control"""
        try:
            # Test manager access
            if self.test_login_with_credentials("manager1", "manager123"):
                users_url = f"{self.base_url}/public/users_management.php"
                response = self.session.get(users_url)
                
                if response.status_code == 200 and "User Management" in response.text:
                    self.log_test("Role-based Access (Manager)", True, "Manager can access user management")
                else:
                    self.log_test("Role-based Access (Manager)", False, "Manager should have access to user management")
                    return False
            
            # Test sales access (should be denied for user creation)
            if self.test_login_with_credentials("sales1", "sales123"):
                users_url = f"{self.base_url}/public/users_management.php"
                response = self.session.get(users_url)
                
                if response.status_code == 200:
                    # Sales should see the page but not have create permissions
                    soup = BeautifulSoup(response.text, 'html.parser')
                    create_button = soup.find('button', string=lambda text: text and 'Create New User' in text)
                    
                    if not create_button:
                        self.log_test("Role-based Access (Sales)", True, "Sales user has limited access (no create button)")
                        return True
                    else:
                        self.log_test("Role-based Access (Sales)", False, "Sales user should not see create button")
                        return False
                else:
                    self.log_test("Role-based Access (Sales)", False, f"Sales user denied access: HTTP {response.status_code}")
                    return False
                    
        except Exception as e:
            self.log_test("Role-based Access", False, f"Error: {str(e)}")
            return False
    
    def test_logout_functionality(self):
        """Test logout functionality"""
        try:
            # Login first
            if not self.test_login_with_credentials("admin", "admin123"):
                return False
            
            # Logout
            logout_url = f"{self.base_url}/public/logout_clean.php"
            response = self.session.get(logout_url, allow_redirects=False)
            
            if response.status_code in [302, 301]:
                # Should redirect to login page
                location = response.headers.get('Location', '')
                if 'login' in location.lower():
                    # Try to access protected page after logout
                    users_url = f"{self.base_url}/public/users_management.php"
                    response = self.session.get(users_url, allow_redirects=False)
                    
                    if response.status_code in [302, 301]:
                        self.log_test("Logout Functionality", True, "Successfully logged out and redirected")
                        return True
                    else:
                        self.log_test("Logout Functionality", False, "Should redirect to login after logout")
                        return False
                else:
                    self.log_test("Logout Functionality", False, f"Unexpected redirect after logout: {location}")
                    return False
            else:
                self.log_test("Logout Functionality", False, f"No redirect on logout: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Logout Functionality", False, f"Error: {str(e)}")
            return False
    
    def run_all_tests(self):
        """Run all authentication tests"""
        print("🧪 Starting PHP Authentication System Tests")
        print("=" * 60)
        
        # Basic connectivity tests
        if not self.test_login_page_access():
            print("❌ Cannot access login page - aborting further tests")
            return False
        
        # Authentication tests
        self.test_login_with_credentials("admin", "admin123", True)
        self.test_login_with_credentials("manager1", "manager123", True)
        self.test_login_with_credentials("sales1", "sales123", True)
        self.test_login_with_credentials("invalid", "invalid", False)
        
        # Session and access tests
        self.test_session_management()
        self.test_users_management_page()
        self.test_user_statistics()
        
        # User management tests
        self.test_user_creation()
        self.test_form_validation()
        self.test_role_based_access()
        
        # Logout test
        self.test_logout_functionality()
        
        # Summary
        print("\n" + "=" * 60)
        print("📊 TEST SUMMARY")
        print("=" * 60)
        
        passed = sum(1 for result in self.test_results if result['success'])
        total = len(self.test_results)
        
        print(f"Total Tests: {total}")
        print(f"Passed: {passed}")
        print(f"Failed: {total - passed}")
        print(f"Success Rate: {(passed/total)*100:.1f}%")
        
        # List failed tests
        failed_tests = [result for result in self.test_results if not result['success']]
        if failed_tests:
            print("\n❌ FAILED TESTS:")
            for test in failed_tests:
                print(f"  • {test['test']}: {test['message']}")
        
        return passed == total

def main():
    """Main test execution"""
    tester = PHPAuthenticationTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! Authentication system is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()