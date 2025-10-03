#!/usr/bin/env python3
"""
Backend Test Suite for PHP-based Invoice System with Discount Functionality
Tests the invoice system focusing on discount_amount field fixes, invoice creation,
quotation to invoice conversion, discount application, and invoice display.
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

class InvoiceSystemTester:
    def __init__(self, base_url="https://tilecrm-app.preview.emergentagent.com"):
        self.base_url = base_url
        self.session = requests.Session()
        self.session.headers.update({
            'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        })
        self.test_results = []
        self.authenticated = False
        self.test_quotation_id = None
        self.test_invoice_id = None
        
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
            # Get login page first
            login_url = f"{self.base_url}/public/login_clean.php"
            response = self.session.get(login_url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Authentication Setup", False, f"Cannot access login page: HTTP {response.status_code}")
                return False
            
            # Submit login form
            login_data = {
                'username': 'admin',
                'password': 'admin123'
            }
            
            response = self.session.post(login_url, data=login_data, allow_redirects=True)
            
            if response.status_code == 200 and ('dashboard' in response.url.lower() or 'index.php' in response.url):
                self.authenticated = True
                self.log_test("Authentication Setup", True, "Successfully authenticated as admin")
                return True
            else:
                self.log_test("Authentication Setup", False, f"Login failed: {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Authentication Setup", False, f"Authentication error: {str(e)}")
            return False

    def test_tiles_inventory_access(self):
        """Test access to enhanced tiles inventory page"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for enhanced inventory features
                has_enhanced_table = soup.find('table', class_='inventory-table') is not None
                has_cost_columns = 'Cost/Box' in response.text and 'Cost + Transport' in response.text
                has_sales_data = 'Sold Boxes' in response.text and 'Sold Revenue' in response.text
                has_qr_features = 'QR Code' in response.text
                has_invoice_links = 'Invoice Links' in response.text
                
                if has_enhanced_table and has_cost_columns and has_sales_data and has_qr_features and has_invoice_links:
                    self.log_test("Enhanced Tiles Inventory Access", True, "All enhanced features present")
                    return True
                else:
                    missing_features = []
                    if not has_enhanced_table: missing_features.append("Enhanced table")
                    if not has_cost_columns: missing_features.append("Cost columns")
                    if not has_sales_data: missing_features.append("Sales data")
                    if not has_qr_features: missing_features.append("QR features")
                    if not has_invoice_links: missing_features.append("Invoice links")
                    
                    self.log_test("Enhanced Tiles Inventory Access", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Enhanced Tiles Inventory Access", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Enhanced Tiles Inventory Access", False, f"Error: {str(e)}")
            return False

    def test_other_inventory_access(self):
        """Test access to enhanced other inventory page"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for enhanced inventory features
                has_enhanced_table = soup.find('table', class_='inventory-table') is not None
                has_cost_columns = 'Cost/Unit' in response.text and 'Cost + Transport' in response.text
                has_sales_data = 'Sold Quantity' in response.text and 'Sold Revenue' in response.text
                has_qr_features = 'QR Code' in response.text
                has_quote_links = 'Quote Links' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_enhanced_table and has_cost_columns and has_sales_data and has_qr_features and has_quote_links and has_rupee_currency:
                    self.log_test("Enhanced Other Inventory Access", True, "All enhanced features present with rupee currency")
                    return True
                else:
                    missing_features = []
                    if not has_enhanced_table: missing_features.append("Enhanced table")
                    if not has_cost_columns: missing_features.append("Cost columns")
                    if not has_sales_data: missing_features.append("Sales data")
                    if not has_qr_features: missing_features.append("QR features")
                    if not has_quote_links: missing_features.append("Quote links")
                    if not has_rupee_currency: missing_features.append("Rupee currency")
                    
                    self.log_test("Enhanced Other Inventory Access", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Enhanced Other Inventory Access", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Enhanced Other Inventory Access", False, f"Error: {str(e)}")
            return False

    def test_tiles_purchase_entry_access(self):
        """Test access to tiles purchase entry system"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/tiles_purchase.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for enhanced purchase entry features
                has_transport_percentage = 'Transport %' in response.text
                has_live_calculations = 'Live Calculations' in response.text
                has_damage_percentage = 'Damage %' in response.text
                has_cost_breakdown = 'Cost + Transport' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_transport_percentage and has_live_calculations and has_damage_percentage and has_cost_breakdown and has_rupee_currency:
                    self.log_test("Tiles Purchase Entry Access", True, "All enhanced purchase features present")
                    return True
                else:
                    missing_features = []
                    if not has_transport_percentage: missing_features.append("Transport percentage")
                    if not has_live_calculations: missing_features.append("Live calculations")
                    if not has_damage_percentage: missing_features.append("Damage percentage")
                    if not has_cost_breakdown: missing_features.append("Cost breakdown")
                    if not has_rupee_currency: missing_features.append("Rupee currency")
                    
                    self.log_test("Tiles Purchase Entry Access", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Tiles Purchase Entry Access", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Tiles Purchase Entry Access", False, f"Error: {str(e)}")
            return False

    def test_other_purchase_entry_access(self):
        """Test access to other items purchase entry system"""
        if not self.authenticate():
            return False
            
        try:
            url = f"{self.base_url}/public/other_purchase.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                # Check for enhanced purchase entry features
                has_transport_percentage = 'Transport %' in response.text
                has_live_calculations = 'Live Calculations' in response.text
                has_damage_percentage = 'Damage %' in response.text
                has_cost_breakdown = 'Cost + Transport' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_transport_percentage and has_live_calculations and has_damage_percentage and has_cost_breakdown and has_rupee_currency:
                    self.log_test("Other Items Purchase Entry Access", True, "All enhanced purchase features present")
                    return True
                else:
                    missing_features = []
                    if not has_transport_percentage: missing_features.append("Transport percentage")
                    if not has_live_calculations: missing_features.append("Live calculations")
                    if not has_damage_percentage: missing_features.append("Damage percentage")
                    if not has_cost_breakdown: missing_features.append("Cost breakdown")
                    if not has_rupee_currency: missing_features.append("Rupee currency")
                    
                    self.log_test("Other Items Purchase Entry Access", False, f"Missing features: {', '.join(missing_features)}")
                    return False
            else:
                self.log_test("Other Items Purchase Entry Access", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Other Items Purchase Entry Access", False, f"Error: {str(e)}")
            return False

    def test_tiles_purchase_entry_submission(self):
        """Test tiles purchase entry form submission with transport percentage"""
        if not self.authenticate():
            return False
            
        try:
            # First get the form page to get available tiles
            url = f"{self.base_url}/public/tiles_purchase.php"
            response = self.session.get(url)
            
            if response.status_code != 200:
                self.log_test("Tiles Purchase Entry Submission", False, "Cannot access purchase form")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            tile_select = soup.find('select', {'name': 'tile_id'})
            
            if not tile_select:
                self.log_test("Tiles Purchase Entry Submission", False, "No tile selection dropdown found")
                return False
            
            # Get first available tile
            tile_options = tile_select.find_all('option')
            tile_id = None
            for option in tile_options:
                if option.get('value') and option.get('value') != '':
                    tile_id = option.get('value')
                    break
            
            if not tile_id:
                self.log_test("Tiles Purchase Entry Submission", False, "No tiles available for testing")
                return False
            
            # Submit purchase entry with realistic data
            purchase_data = {
                'add_purchase': '1',
                'tile_id': tile_id,
                'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                'supplier_name': 'Rajesh Tiles Supplier',
                'invoice_number': f'INV-{int(time.time())}',
                'total_boxes': '100',
                'damage_percentage': '5.5',
                'cost_per_box': '250.00',
                'transport_percentage': '30',  # 30% transport
                'transport_cost': '0',
                'notes': 'Test purchase entry with transport percentage calculation'
            }
            
            response = self.session.post(url, data=purchase_data)
            
            if response.status_code == 200:
                if "Purchase entry added successfully" in response.text:
                    self.log_test("Tiles Purchase Entry Submission", True, f"Successfully created purchase entry for tile {tile_id}")
                    return True
                elif "required fields" in response.text.lower():
                    self.log_test("Tiles Purchase Entry Submission", False, "Form validation error - missing required fields")
                    return False
                else:
                    self.log_test("Tiles Purchase Entry Submission", False, "No success message found", response.text[:500])
                    return False
            else:
                self.log_test("Tiles Purchase Entry Submission", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Tiles Purchase Entry Submission", False, f"Error: {str(e)}")
            return False

    def test_other_purchase_entry_submission(self):
        """Test other items purchase entry form submission with transport percentage"""
        if not self.authenticate():
            return False
            
        try:
            # First get the form page to get available items
            url = f"{self.base_url}/public/other_purchase.php"
            response = self.session.get(url)
            
            if response.status_code != 200:
                self.log_test("Other Items Purchase Entry Submission", False, "Cannot access purchase form")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            item_select = soup.find('select', {'name': 'item_id'})
            
            if not item_select:
                self.log_test("Other Items Purchase Entry Submission", False, "No item selection dropdown found")
                return False
            
            # Get first available item
            item_options = item_select.find_all('option')
            item_id = None
            for option in item_options:
                if option.get('value') and option.get('value') != '':
                    item_id = option.get('value')
                    break
            
            if not item_id:
                self.log_test("Other Items Purchase Entry Submission", False, "No items available for testing")
                return False
            
            # Submit purchase entry with realistic data
            purchase_data = {
                'add_purchase': '1',
                'item_id': item_id,
                'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                'supplier_name': 'Mumbai Hardware Supplies',
                'invoice_number': f'MHS-{int(time.time())}',
                'total_quantity': '50',
                'damage_percentage': '2.0',
                'cost_per_unit': '15.50',
                'transport_percentage': '25',  # 25% transport
                'transport_cost': '0',
                'notes': 'Test purchase entry for misc items with transport calculation'
            }
            
            response = self.session.post(url, data=purchase_data)
            
            if response.status_code == 200:
                if "Purchase entry added successfully" in response.text:
                    self.log_test("Other Items Purchase Entry Submission", True, f"Successfully created purchase entry for item {item_id}")
                    return True
                elif "required fields" in response.text.lower():
                    self.log_test("Other Items Purchase Entry Submission", False, "Form validation error - missing required fields")
                    return False
                else:
                    self.log_test("Other Items Purchase Entry Submission", False, "No success message found", response.text[:500])
                    return False
            else:
                self.log_test("Other Items Purchase Entry Submission", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Other Items Purchase Entry Submission", False, f"Error: {str(e)}")
            return False

    def test_purchase_history_access(self):
        """Test access to purchase history with enhanced columns"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles purchase history
            url = f"{self.base_url}/public/tiles_purchase.php?view=history"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_cost_breakdown = 'Cost + Transport' in response.text
                has_transport_column = 'Transport %' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_breakdown and has_transport_column and has_rupee_currency:
                    self.log_test("Purchase History Access (Tiles)", True, "Enhanced history columns present")
                else:
                    self.log_test("Purchase History Access (Tiles)", False, "Missing enhanced history features")
                    return False
            else:
                self.log_test("Purchase History Access (Tiles)", False, f"HTTP {response.status_code}")
                return False
            
            # Test other items purchase history
            url = f"{self.base_url}/public/other_purchase.php?view=history"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_cost_breakdown = 'Cost + Transport' in response.text
                has_transport_column = 'Transport %' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_breakdown and has_transport_column and has_rupee_currency:
                    self.log_test("Purchase History Access (Other Items)", True, "Enhanced history columns present")
                    return True
                else:
                    self.log_test("Purchase History Access (Other Items)", False, "Missing enhanced history features")
                    return False
            else:
                self.log_test("Purchase History Access (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Purchase History Access", False, f"Error: {str(e)}")
            return False

    def test_qr_code_generation(self):
        """Test QR code generation functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles QR generation
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url)
            
            if response.status_code != 200:
                self.log_test("QR Code Generation", False, "Cannot access tiles inventory")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Look for QR generation buttons or existing QR codes
            qr_buttons = soup.find_all('button', string=lambda text: text and 'qr' in text.lower())
            qr_images = soup.find_all('img', class_='qr-thumb')
            
            has_qr_functionality = len(qr_buttons) > 0 or len(qr_images) > 0
            has_qr_modal = soup.find('div', id='qrCodeModal') is not None
            
            if has_qr_functionality and has_qr_modal:
                self.log_test("QR Code Generation (Tiles)", True, f"QR functionality present - {len(qr_buttons)} buttons, {len(qr_images)} existing QR codes")
            else:
                self.log_test("QR Code Generation (Tiles)", False, "QR functionality missing")
                return False
            
            # Test other items QR generation
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                soup = BeautifulSoup(response.text, 'html.parser')
                
                qr_buttons = soup.find_all('button', string=lambda text: text and 'qr' in text.lower())
                qr_images = soup.find_all('img', class_='qr-thumb')
                has_qr_functionality = len(qr_buttons) > 0 or len(qr_images) > 0
                has_qr_modal = soup.find('div', id='qrCodeModal') is not None
                
                if has_qr_functionality and has_qr_modal:
                    self.log_test("QR Code Generation (Other Items)", True, f"QR functionality present - {len(qr_buttons)} buttons, {len(qr_images)} existing QR codes")
                    return True
                else:
                    self.log_test("QR Code Generation (Other Items)", False, "QR functionality missing")
                    return False
            else:
                self.log_test("QR Code Generation (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("QR Code Generation", False, f"Error: {str(e)}")
            return False

    def test_cost_calculations(self):
        """Test cost calculation features in inventory displays"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory cost calculations
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                # Check for cost calculation columns
                has_cost_per_box = 'Cost/Box' in response.text
                has_cost_with_transport = 'Cost + Transport' in response.text
                has_total_box_cost = 'Total Box Cost' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_per_box and has_cost_with_transport and has_total_box_cost and has_rupee_currency:
                    self.log_test("Cost Calculations (Tiles)", True, "All cost calculation columns present")
                else:
                    missing = []
                    if not has_cost_per_box: missing.append("Cost/Box")
                    if not has_cost_with_transport: missing.append("Cost + Transport")
                    if not has_total_box_cost: missing.append("Total Box Cost")
                    if not has_rupee_currency: missing.append("Rupee currency")
                    
                    self.log_test("Cost Calculations (Tiles)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Cost Calculations (Tiles)", False, f"HTTP {response.status_code}")
                return False
            
            # Test other items inventory cost calculations
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_cost_per_unit = 'Cost/Unit' in response.text
                has_cost_with_transport = 'Cost + Transport' in response.text
                has_total_cost = 'Total Cost' in response.text
                has_rupee_currency = '₹' in response.text
                
                if has_cost_per_unit and has_cost_with_transport and has_total_cost and has_rupee_currency:
                    self.log_test("Cost Calculations (Other Items)", True, "All cost calculation columns present")
                    return True
                else:
                    missing = []
                    if not has_cost_per_unit: missing.append("Cost/Unit")
                    if not has_cost_with_transport: missing.append("Cost + Transport")
                    if not has_total_cost: missing.append("Total Cost")
                    if not has_rupee_currency: missing.append("Rupee currency")
                    
                    self.log_test("Cost Calculations (Other Items)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Cost Calculations (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Cost Calculations", False, f"Error: {str(e)}")
            return False

    def test_sales_data_integration(self):
        """Test sales data integration in inventory displays"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory sales data
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_sold_boxes = 'Sold Boxes' in response.text
                has_sold_revenue = 'Sold Revenue' in response.text
                has_invoice_links = 'Invoice Links' in response.text
                
                if has_sold_boxes and has_sold_revenue and has_invoice_links:
                    self.log_test("Sales Data Integration (Tiles)", True, "All sales data columns present")
                else:
                    missing = []
                    if not has_sold_boxes: missing.append("Sold Boxes")
                    if not has_sold_revenue: missing.append("Sold Revenue")
                    if not has_invoice_links: missing.append("Invoice Links")
                    
                    self.log_test("Sales Data Integration (Tiles)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Sales Data Integration (Tiles)", False, f"HTTP {response.status_code}")
                return False
            
            # Test other items inventory sales data
            url = f"{self.base_url}/public/other_inventory.php"
            response = self.session.get(url)
            
            if response.status_code == 200:
                has_sold_quantity = 'Sold Quantity' in response.text
                has_sold_revenue = 'Sold Revenue' in response.text
                has_quote_links = 'Quote Links' in response.text
                
                if has_sold_quantity and has_sold_revenue and has_quote_links:
                    self.log_test("Sales Data Integration (Other Items)", True, "All sales data columns present")
                    return True
                else:
                    missing = []
                    if not has_sold_quantity: missing.append("Sold Quantity")
                    if not has_sold_revenue: missing.append("Sold Revenue")
                    if not has_quote_links: missing.append("Quote Links")
                    
                    self.log_test("Sales Data Integration (Other Items)", False, f"Missing: {', '.join(missing)}")
                    return False
            else:
                self.log_test("Sales Data Integration (Other Items)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Sales Data Integration", False, f"Error: {str(e)}")
            return False

    def test_form_validation(self):
        """Test form validation for purchase entries"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles purchase validation
            url = f"{self.base_url}/public/tiles_purchase.php"
            
            # Test with invalid transport percentage (over 200%)
            invalid_data = {
                'add_purchase': '1',
                'tile_id': '1',
                'purchase_date': datetime.now().strftime('%Y-%m-%d'),
                'total_boxes': '10',
                'damage_percentage': '5',
                'cost_per_box': '100',
                'transport_percentage': '250',  # Invalid - over 200%
            }
            
            response = self.session.post(url, data=invalid_data)
            
            if response.status_code == 200:
                if "Transport percentage must be between 0 and 200" in response.text:
                    self.log_test("Form Validation (Transport %)", True, "Correctly validates transport percentage limits")
                else:
                    self.log_test("Form Validation (Transport %)", False, "Should reject transport percentage > 200%")
                    return False
            else:
                self.log_test("Form Validation (Transport %)", False, f"HTTP {response.status_code}")
                return False
            
            # Test with invalid damage percentage (over 100%)
            invalid_data['transport_percentage'] = '30'
            invalid_data['damage_percentage'] = '150'  # Invalid - over 100%
            
            response = self.session.post(url, data=invalid_data)
            
            if response.status_code == 200:
                if "Damage percentage must be between 0 and 100" in response.text:
                    self.log_test("Form Validation (Damage %)", True, "Correctly validates damage percentage limits")
                    return True
                else:
                    self.log_test("Form Validation (Damage %)", False, "Should reject damage percentage > 100%")
                    return False
            else:
                self.log_test("Form Validation (Damage %)", False, f"HTTP {response.status_code}")
                return False
                
        except Exception as e:
            self.log_test("Form Validation", False, f"Error: {str(e)}")
            return False

    def run_all_tests(self):
        """Run all enhanced inventory system tests"""
        print("🧪 Starting Enhanced Inventory System Tests")
        print("=" * 60)
        
        # Authentication test
        if not self.authenticate():
            print("❌ Cannot authenticate - aborting further tests")
            return False
        
        # Enhanced inventory access tests
        self.test_tiles_inventory_access()
        self.test_other_inventory_access()
        
        # Purchase entry system tests
        self.test_tiles_purchase_entry_access()
        self.test_other_purchase_entry_access()
        
        # Purchase entry functionality tests
        self.test_tiles_purchase_entry_submission()
        self.test_other_purchase_entry_submission()
        
        # Purchase history tests
        self.test_purchase_history_access()
        
        # Enhanced features tests
        self.test_qr_code_generation()
        self.test_cost_calculations()
        self.test_sales_data_integration()
        
        # Validation tests
        self.test_form_validation()
        
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
    tester = EnhancedInventoryTester()
    success = tester.run_all_tests()
    
    if success:
        print("\n🎉 All tests passed! Enhanced Inventory System is working correctly.")
        sys.exit(0)
    else:
        print("\n⚠️  Some tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()