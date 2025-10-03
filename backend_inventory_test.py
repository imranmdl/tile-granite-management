#!/usr/bin/env python3
"""
Backend Test Suite for Enhanced Inventory System
Tests the PHP-based Enhanced Inventory system with purchase entries, damage calculations, and database operations.
"""

import requests
import json
import time
from urllib.parse import urljoin, urlparse, parse_qs
import re
from bs4 import BeautifulSoup
import sys
from datetime import datetime, timedelta
import random

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
        
    def authenticate(self):
        """Authenticate with admin credentials"""
        if self.authenticated:
            return True
            
        try:
            login_url = f"{self.base_url}/public/login_clean.php"
            
            # Get login page first
            response = self.session.get(login_url)
            if response.status_code != 200:
                self.log_test("Authentication Setup", False, f"Cannot access login page: HTTP {response.status_code}")
                return False
            
            # Submit login credentials
            login_data = {
                'username': 'admin',
                'password': 'admin123'
            }
            
            response = self.session.post(login_url, data=login_data, allow_redirects=False)
            
            if response.status_code in [302, 301]:
                self.authenticated = True
                self.log_test("Authentication Setup", True, "Successfully authenticated as admin")
                return True
            else:
                self.log_test("Authentication Setup", False, f"Login failed: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Authentication Setup", False, f"Authentication error: {str(e)}")
            return False
    
    def test_database_schema(self):
        """Test if enhanced inventory database schema exists"""
        try:
            if not self.authenticate():
                return False
            
            # Check if we can access the purchase pages (indirect schema test)
            tiles_purchase_url = f"{self.base_url}/public/tiles_purchase.php"
            response = self.session.get(tiles_purchase_url)
            
            if response.status_code == 200:
                # Look for form elements that indicate proper schema
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for key form fields that indicate schema is working
                required_fields = ['tile_id', 'purchase_date', 'total_boxes', 'damage_percentage', 'cost_per_box']
                found_fields = []
                
                for field in required_fields:
                    if soup.find('input', {'name': field}) or soup.find('select', {'name': field}):
                        found_fields.append(field)
                
                if len(found_fields) >= 4:
                    self.log_test("Database Schema - Tiles Purchase", True, f"Found {len(found_fields)}/{len(required_fields)} required fields")
                else:
                    self.log_test("Database Schema - Tiles Purchase", False, f"Missing fields: {set(required_fields) - set(found_fields)}")
                    return False
            else:
                self.log_test("Database Schema - Tiles Purchase", False, f"Cannot access tiles purchase page: HTTP {response.status_code}")
                return False
            
            # Test other purchase page
            other_purchase_url = f"{self.base_url}/public/other_purchase.php"
            response = self.session.get(other_purchase_url)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                required_fields = ['item_id', 'purchase_date', 'total_quantity', 'damage_percentage', 'cost_per_unit']
                found_fields = []
                
                for field in required_fields:
                    if soup.find('input', {'name': field}) or soup.find('select', {'name': field}):
                        found_fields.append(field)
                
                if len(found_fields) >= 4:
                    self.log_test("Database Schema - Other Purchase", True, f"Found {len(found_fields)}/{len(required_fields)} required fields")
                    return True
                else:
                    self.log_test("Database Schema - Other Purchase", False, f"Missing fields: {set(required_fields) - set(found_fields)}")
                    return False
            else:
                self.log_test("Database Schema - Other Purchase", False, f"Cannot access other purchase page: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Database Schema", False, f"Error: {str(e)}")
            return False
    
    def test_tiles_purchase_entry(self):
        """Test tiles purchase entry functionality"""
        try:
            if not self.authenticate():
                return False
            
            tiles_purchase_url = f"{self.base_url}/public/tiles_purchase.php"
            
            # First, get the page to see available tiles
            response = self.session.get(tiles_purchase_url)
            if response.status_code != 200:
                self.log_test("Tiles Purchase Entry", False, f"Cannot access page: HTTP {response.status_code}")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Find tile options
            tile_select = soup.find('select', {'name': 'tile_id'})
            if not tile_select:
                self.log_test("Tiles Purchase Entry", False, "No tile selection dropdown found")
                return False
            
            tile_options = tile_select.find_all('option')
            if len(tile_options) <= 1:  # Only default option
                self.log_test("Tiles Purchase Entry", False, "No tiles available for purchase entry")
                return False
            
            # Get first available tile ID
            tile_id = tile_options[1]['value']  # Skip the first default option
            
            # Test purchase entry with realistic data
            purchase_data = {
                'add_purchase': '1',
                'tile_id': tile_id,
                'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                'supplier_name': 'Test Supplier Ltd',
                'invoice_number': f'INV-{int(time.time())}',
                'total_boxes': '100',
                'damage_percentage': '5.5',
                'cost_per_box': '250.00',
                'transport_cost': '500.00',
                'notes': 'Test purchase entry for backend testing'
            }
            
            response = self.session.post(tiles_purchase_url, data=purchase_data)
            
            if response.status_code == 200:
                if "Purchase entry added successfully" in response.text:
                    self.log_test("Tiles Purchase Entry", True, f"Successfully added purchase entry for tile ID {tile_id}")
                    return True
                elif "Please fill in all required fields" in response.text:
                    self.log_test("Tiles Purchase Entry", False, "Form validation error - required fields missing")
                    return False
                elif "Damage percentage must be between 0 and 100" in response.text:
                    self.log_test("Tiles Purchase Entry", False, "Damage percentage validation error")
                    return False
                else:
                    self.log_test("Tiles Purchase Entry", False, "No success message found", response.text[:500])
                    return False
            else:
                self.log_test("Tiles Purchase Entry", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Tiles Purchase Entry", False, f"Error: {str(e)}")
            return False
    
    def test_other_purchase_entry(self):
        """Test other items purchase entry functionality"""
        try:
            if not self.authenticate():
                return False
            
            other_purchase_url = f"{self.base_url}/public/other_purchase.php"
            
            # Get the page to see available items
            response = self.session.get(other_purchase_url)
            if response.status_code != 200:
                self.log_test("Other Purchase Entry", False, f"Cannot access page: HTTP {response.status_code}")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Find item options
            item_select = soup.find('select', {'name': 'item_id'})
            if not item_select:
                self.log_test("Other Purchase Entry", False, "No item selection dropdown found")
                return False
            
            item_options = item_select.find_all('option')
            if len(item_options) <= 1:  # Only default option
                self.log_test("Other Purchase Entry", False, "No items available for purchase entry")
                return False
            
            # Get first available item ID
            item_id = item_options[1]['value']  # Skip the first default option
            
            # Test purchase entry with realistic data
            purchase_data = {
                'add_purchase': '1',
                'item_id': item_id,
                'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                'supplier_name': 'Test Hardware Supplier',
                'invoice_number': f'HW-{int(time.time())}',
                'total_quantity': '50',
                'damage_percentage': '2.0',
                'cost_per_unit': '15.50',
                'transport_cost': '200.00',
                'notes': 'Test purchase entry for other items backend testing'
            }
            
            response = self.session.post(other_purchase_url, data=purchase_data)
            
            if response.status_code == 200:
                if "Purchase entry added successfully" in response.text:
                    self.log_test("Other Purchase Entry", True, f"Successfully added purchase entry for item ID {item_id}")
                    return True
                elif "Please fill in all required fields" in response.text:
                    self.log_test("Other Purchase Entry", False, "Form validation error - required fields missing")
                    return False
                elif "Damage percentage must be between 0 and 100" in response.text:
                    self.log_test("Other Purchase Entry", False, "Damage percentage validation error")
                    return False
                else:
                    self.log_test("Other Purchase Entry", False, "No success message found", response.text[:500])
                    return False
            else:
                self.log_test("Other Purchase Entry", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Other Purchase Entry", False, f"Error: {str(e)}")
            return False
    
    def test_damage_calculations(self):
        """Test damage percentage calculations"""
        try:
            if not self.authenticate():
                return False
            
            # Test with tiles purchase
            tiles_purchase_url = f"{self.base_url}/public/tiles_purchase.php"
            response = self.session.get(tiles_purchase_url)
            
            if response.status_code != 200:
                self.log_test("Damage Calculations", False, f"Cannot access tiles purchase page: HTTP {response.status_code}")
                return False
            
            # Check if JavaScript for live calculations is present
            if "damage_percentage" in response.text and "usable" in response.text.lower():
                self.log_test("Damage Calculations - UI", True, "Damage calculation elements found in UI")
            else:
                self.log_test("Damage Calculations - UI", False, "Damage calculation elements missing from UI")
                return False
            
            # Test edge cases for damage percentage validation
            soup = BeautifulSoup(response.text, 'html.parser')
            tile_select = soup.find('select', {'name': 'tile_id'})
            
            if tile_select:
                tile_options = tile_select.find_all('option')
                if len(tile_options) > 1:
                    tile_id = tile_options[1]['value']
                    
                    # Test with invalid damage percentage (over 100%)
                    invalid_data = {
                        'add_purchase': '1',
                        'tile_id': tile_id,
                        'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                        'supplier_name': 'Test Supplier',
                        'invoice_number': f'TEST-{int(time.time())}',
                        'total_boxes': '10',
                        'damage_percentage': '150',  # Invalid - over 100%
                        'cost_per_box': '100.00',
                        'transport_cost': '50.00'
                    }
                    
                    response = self.session.post(tiles_purchase_url, data=invalid_data)
                    
                    if "Damage percentage must be between 0 and 100" in response.text:
                        self.log_test("Damage Calculations - Validation", True, "Correctly validates damage percentage limits")
                        return True
                    else:
                        self.log_test("Damage Calculations - Validation", False, "Should reject damage percentage over 100%")
                        return False
            
            self.log_test("Damage Calculations", False, "Could not find tiles to test with")
            return False
                
        except Exception as e:
            self.log_test("Damage Calculations", False, f"Error: {str(e)}")
            return False
    
    def test_purchase_history(self):
        """Test purchase history display"""
        try:
            if not self.authenticate():
                return False
            
            # Test tiles purchase history
            tiles_purchase_url = f"{self.base_url}/public/tiles_purchase.php?view=history"
            response = self.session.get(tiles_purchase_url)
            
            if response.status_code == 200:
                if "Purchase History" in response.text or "purchase" in response.text.lower():
                    self.log_test("Purchase History - Tiles", True, "Tiles purchase history page accessible")
                else:
                    self.log_test("Purchase History - Tiles", False, "Purchase history content not found")
                    return False
            else:
                self.log_test("Purchase History - Tiles", False, f"Cannot access tiles history: HTTP {response.status_code}")
                return False
            
            # Test other items purchase history
            other_purchase_url = f"{self.base_url}/public/other_purchase.php?view=history"
            response = self.session.get(other_purchase_url)
            
            if response.status_code == 200:
                if "Purchase History" in response.text or "purchase" in response.text.lower():
                    self.log_test("Purchase History - Other", True, "Other items purchase history page accessible")
                    return True
                else:
                    self.log_test("Purchase History - Other", False, "Purchase history content not found")
                    return False
            else:
                self.log_test("Purchase History - Other", False, f"Cannot access other history: HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Purchase History", False, f"Error: {str(e)}")
            return False
    
    def test_form_validation(self):
        """Test form validation for purchase entries"""
        try:
            if not self.authenticate():
                return False
            
            tiles_purchase_url = f"{self.base_url}/public/tiles_purchase.php"
            
            # Test with missing required fields
            invalid_data = {
                'add_purchase': '1',
                'tile_id': '',  # Missing
                'purchase_date': '',  # Missing
                'total_boxes': '',  # Missing
                'cost_per_box': ''  # Missing
            }
            
            response = self.session.post(tiles_purchase_url, data=invalid_data)
            
            if response.status_code == 200:
                if "Please fill in all required fields" in response.text:
                    self.log_test("Form Validation - Required Fields", True, "Correctly validates required fields")
                else:
                    self.log_test("Form Validation - Required Fields", False, "Should validate required fields")
                    return False
            else:
                self.log_test("Form Validation - Required Fields", False, f"HTTP {response.status_code}")
                return False
            
            # Test with negative values
            response = self.session.get(tiles_purchase_url)
            soup = BeautifulSoup(response.text, 'html.parser')
            tile_select = soup.find('select', {'name': 'tile_id'})
            
            if tile_select:
                tile_options = tile_select.find_all('option')
                if len(tile_options) > 1:
                    tile_id = tile_options[1]['value']
                    
                    negative_data = {
                        'add_purchase': '1',
                        'tile_id': tile_id,
                        'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                        'total_boxes': '-10',  # Negative value
                        'cost_per_box': '-50',  # Negative value
                        'damage_percentage': '5'
                    }
                    
                    response = self.session.post(tiles_purchase_url, data=negative_data)
                    
                    if "Please fill in all required fields with valid values" in response.text:
                        self.log_test("Form Validation - Negative Values", True, "Correctly validates negative values")
                        return True
                    else:
                        self.log_test("Form Validation - Negative Values", False, "Should reject negative values")
                        return False
            
            self.log_test("Form Validation", False, "Could not complete validation tests")
            return False
                
        except Exception as e:
            self.log_test("Form Validation", False, f"Error: {str(e)}")
            return False
    
    def run_backend_tests(self):
        """Run all backend tests for Enhanced Inventory system"""
        print("🧪 Starting Enhanced Inventory Backend Tests")
        print("=" * 60)
        
        # Authentication test
        if not self.authenticate():
            print("❌ Cannot authenticate - aborting further tests")
            return False
        
        # Database schema tests
        self.test_database_schema()
        
        # Purchase entry tests
        self.test_tiles_purchase_entry()
        self.test_other_purchase_entry()
        
        # Calculation and validation tests
        self.test_damage_calculations()
        self.test_form_validation()
        
        # History tests
        self.test_purchase_history()
        
        # Summary
        print("\n" + "=" * 60)
        print("📊 BACKEND TEST SUMMARY")
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
    tester = EnhancedInventoryTester()
    success = tester.run_backend_tests()
    
    if success:
        print("\n🎉 All backend tests passed! Enhanced Inventory system backend is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some backend tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()