#!/usr/bin/env python3
"""
Targeted test for specific inventory system features
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

class TargetedInventoryTester:
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

    def test_qr_code_functionality_detailed(self):
        """Detailed test of QR code functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory QR functionality
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("QR Code Functionality - Tiles", False, "Cannot access tiles inventory")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Check for QR modal
            qr_modal = soup.find('div', id='qrCodeModal')
            has_qr_modal = qr_modal is not None
            
            # Check for QR generation buttons/links
            qr_buttons = soup.find_all('button', string=lambda text: text and 'qr' in text.lower())
            qr_links = soup.find_all('a', href=lambda href: href and 'qr' in href.lower())
            
            # Check for existing QR images
            qr_images = soup.find_all('img', class_='qr-thumb')
            qr_img_tags = soup.find_all('img', src=lambda src: src and 'qr' in src.lower())
            
            # Check for QR-related JavaScript functions
            has_qr_js = 'printQRCodes' in response.text or 'generateQR' in response.text
            
            print(f"QR Modal found: {has_qr_modal}")
            print(f"QR Buttons: {len(qr_buttons)}")
            print(f"QR Links: {len(qr_links)}")
            print(f"QR Images: {len(qr_images)}")
            print(f"QR Image tags: {len(qr_img_tags)}")
            print(f"QR JavaScript: {has_qr_js}")
            
            if has_qr_modal and (len(qr_buttons) > 0 or len(qr_links) > 0 or has_qr_js):
                self.log_test("QR Code Functionality - Tiles", True, f"QR functionality present - Modal: {has_qr_modal}, Buttons: {len(qr_buttons)}, JS: {has_qr_js}")
                return True
            else:
                self.log_test("QR Code Functionality - Tiles", False, f"QR functionality incomplete - Modal: {has_qr_modal}, Buttons: {len(qr_buttons)}, JS: {has_qr_js}")
                return False
                
        except Exception as e:
            self.log_test("QR Code Functionality - Tiles", False, f"Error: {str(e)}")
            return False

    def test_purchase_history_detailed(self):
        """Detailed test of purchase history enhanced columns"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles purchase history
            url = f"{self.base_url}/public/tiles_purchase.php?view=history"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Purchase History - Tiles", False, f"Cannot access purchase history: HTTP {response.status_code}")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Look for table headers
            table_headers = soup.find_all('th')
            header_text = [th.get_text().strip() for th in table_headers]
            
            print(f"Found table headers: {header_text}")
            
            # Check for enhanced columns
            has_cost_transport = any('Cost + Transport' in header or 'Cost+Transport' in header for header in header_text)
            has_transport_percent = any('Transport %' in header or 'Transport' in header for header in header_text)
            has_rupee_currency = '₹' in response.text
            
            # Also check in the response text directly
            has_cost_transport_text = 'Cost + Transport' in response.text or 'Cost+Transport' in response.text
            has_transport_text = 'Transport %' in response.text or 'Transport Percentage' in response.text
            
            print(f"Cost + Transport in headers: {has_cost_transport}")
            print(f"Transport % in headers: {has_transport_percent}")
            print(f"Cost + Transport in text: {has_cost_transport_text}")
            print(f"Transport in text: {has_transport_text}")
            print(f"Rupee currency: {has_rupee_currency}")
            
            if (has_cost_transport or has_cost_transport_text) and (has_transport_percent or has_transport_text) and has_rupee_currency:
                self.log_test("Purchase History - Tiles", True, "Enhanced history columns present")
                return True
            else:
                missing = []
                if not (has_cost_transport or has_cost_transport_text): missing.append("Cost + Transport")
                if not (has_transport_percent or has_transport_text): missing.append("Transport %")
                if not has_rupee_currency: missing.append("Rupee currency")
                
                self.log_test("Purchase History - Tiles", False, f"Missing enhanced features: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("Purchase History - Tiles", False, f"Error: {str(e)}")
            return False

    def test_stock_adjustment_functionality(self):
        """Test stock adjustment functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory for stock adjustment
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Stock Adjustment Functionality", False, "Cannot access tiles inventory")
                return False
            
            # Check for stock adjustment features
            has_adjust_stock = 'Adjust Stock' in response.text or 'Stock Adjustment' in response.text
            has_adjustment_modal = 'adjustmentModal' in response.text or 'stockAdjustmentModal' in response.text
            has_adjustment_form = 'adjustment_reason' in response.text or 'stock_adjustment' in response.text
            
            print(f"Adjust Stock text: {has_adjust_stock}")
            print(f"Adjustment Modal: {has_adjustment_modal}")
            print(f"Adjustment Form: {has_adjustment_form}")
            
            if has_adjust_stock and (has_adjustment_modal or has_adjustment_form):
                self.log_test("Stock Adjustment Functionality", True, "Stock adjustment features present")
                return True
            else:
                self.log_test("Stock Adjustment Functionality", False, "Stock adjustment features missing")
                return False
                
        except Exception as e:
            self.log_test("Stock Adjustment Functionality", False, f"Error: {str(e)}")
            return False

    def test_export_functionality(self):
        """Test CSV export functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory export
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Export Functionality", False, "Cannot access tiles inventory")
                return False
            
            # Check for export features
            has_export_csv = 'Export CSV' in response.text or 'export.php' in response.text
            has_export_button = 'btn-export' in response.text or 'export-btn' in response.text
            
            print(f"Export CSV text: {has_export_csv}")
            print(f"Export Button: {has_export_button}")
            
            if has_export_csv or has_export_button:
                self.log_test("Export Functionality", True, "Export features present")
                return True
            else:
                self.log_test("Export Functionality", False, "Export features missing")
                return False
                
        except Exception as e:
            self.log_test("Export Functionality", False, f"Error: {str(e)}")
            return False

    def test_vendor_filtering(self):
        """Test vendor filtering functionality"""
        if not self.authenticate():
            return False
            
        try:
            # Test tiles inventory vendor filtering
            url = f"{self.base_url}/public/tiles_inventory.php"
            response = self.session.get(url, timeout=10)
            
            if response.status_code != 200:
                self.log_test("Vendor Filtering", False, "Cannot access tiles inventory")
                return False
            
            soup = BeautifulSoup(response.text, 'html.parser')
            
            # Check for vendor filter dropdown
            vendor_select = soup.find('select', {'name': 'vendor_filter'}) or soup.find('select', id='vendorFilter')
            has_vendor_filter = vendor_select is not None
            
            # Check for "No Vendor" and "All Vendors" options
            has_no_vendor_option = 'No Vendor' in response.text
            has_all_vendors_option = 'All Vendors' in response.text
            
            print(f"Vendor Filter Dropdown: {has_vendor_filter}")
            print(f"No Vendor Option: {has_no_vendor_option}")
            print(f"All Vendors Option: {has_all_vendors_option}")
            
            if has_vendor_filter and has_no_vendor_option and has_all_vendors_option:
                self.log_test("Vendor Filtering", True, "Vendor filtering functionality present")
                return True
            else:
                missing = []
                if not has_vendor_filter: missing.append("Vendor filter dropdown")
                if not has_no_vendor_option: missing.append("No Vendor option")
                if not has_all_vendors_option: missing.append("All Vendors option")
                
                self.log_test("Vendor Filtering", False, f"Missing features: {', '.join(missing)}")
                return False
                
        except Exception as e:
            self.log_test("Vendor Filtering", False, f"Error: {str(e)}")
            return False

    def run_targeted_tests(self):
        """Run targeted tests for specific functionality"""
        print("🎯 Starting Targeted Inventory System Tests")
        print("=" * 60)
        
        # Authentication test
        if not self.authenticate():
            print("❌ Cannot authenticate - aborting further tests")
            return False
        
        # Run targeted tests
        self.test_qr_code_functionality_detailed()
        self.test_purchase_history_detailed()
        self.test_stock_adjustment_functionality()
        self.test_export_functionality()
        self.test_vendor_filtering()
        
        # Summary
        print("\n" + "=" * 60)
        print("📊 TARGETED TEST SUMMARY")
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
    tester = TargetedInventoryTester()
    success = tester.run_targeted_tests()
    
    if success:
        print("\n🎉 All targeted tests passed!")
        sys.exit(0)
    else:
        print("\n⚠️  Some targeted tests failed. Please review the issues above.")
        sys.exit(1)

if __name__ == "__main__":
    main()