#!/usr/bin/env python3
"""
Comprehensive backend API testing for Tile Inventory Management System
Tests all FastAPI endpoints for functionality and integration
"""
import requests
import sys
import json
from datetime import datetime, timezone
from typing import Dict, Any, List

class TileInventoryAPITester:
    def __init__(self, base_url="https://inventory-tracker-159.preview.emergentagent.com"):
        self.base_url = base_url
        self.api_url = f"{base_url}/api"
        self.tests_run = 0
        self.tests_passed = 0
        self.created_items = {
            'tile_sizes': [],
            'tiles': [],
            'misc_items': [],
            'purchase_entries': [],
            'quotations': [],
            'quotation_items': []
        }

    def log_test(self, name: str, success: bool, details: str = ""):
        """Log test result"""
        self.tests_run += 1
        if success:
            self.tests_passed += 1
            print(f"✅ {name}")
        else:
            print(f"❌ {name} - {details}")
        
        if details and success:
            print(f"   ℹ️  {details}")

    def make_request(self, method: str, endpoint: str, data: Dict = None, params: Dict = None) -> tuple:
        """Make HTTP request and return (success, response_data, status_code)"""
        url = f"{self.api_url}/{endpoint}"
        headers = {'Content-Type': 'application/json'}
        
        try:
            if method == 'GET':
                response = requests.get(url, headers=headers, params=params)
            elif method == 'POST':
                response = requests.post(url, json=data, headers=headers)
            elif method == 'PUT':
                response = requests.put(url, json=data, headers=headers)
            elif method == 'DELETE':
                response = requests.delete(url, headers=headers)
            else:
                return False, {}, 0
            
            try:
                response_data = response.json()
            except:
                response_data = {"raw_response": response.text}
            
            return response.status_code < 400, response_data, response.status_code
            
        except Exception as e:
            return False, {"error": str(e)}, 0

    def test_api_health(self):
        """Test basic API connectivity"""
        print("\n🔍 Testing API Health...")
        success, data, status = self.make_request('GET', '')
        self.log_test("API Health Check", success, f"Status: {status}, Response: {data.get('message', 'No message')}")
        return success

    def test_tile_sizes_crud(self):
        """Test tile sizes CRUD operations"""
        print("\n🔍 Testing Tile Sizes Management...")
        
        # Create tile size
        size_data = {
            "name": "Test Size 6x6",
            "length_inches": 6.0,
            "width_inches": 6.0,
            "sqft_per_box": 3.0
        }
        
        success, data, status = self.make_request('POST', 'tile-sizes', size_data)
        self.log_test("Create Tile Size", success, f"Status: {status}")
        
        if success and 'id' in data:
            self.created_items['tile_sizes'].append(data['id'])
            
            # Get all tile sizes
            success, data, status = self.make_request('GET', 'tile-sizes')
            self.log_test("Get Tile Sizes", success, f"Found {len(data) if isinstance(data, list) else 0} sizes")
            
            return True
        return False

    def test_tiles_crud(self):
        """Test tiles CRUD operations"""
        print("\n🔍 Testing Tiles Management...")
        
        # First ensure we have a tile size
        if not self.created_items['tile_sizes']:
            self.test_tile_sizes_crud()
        
        if not self.created_items['tile_sizes']:
            self.log_test("Create Tile", False, "No tile size available")
            return False
        
        # Create tile
        tile_data = {
            "name": "Test Marble Tile",
            "size_id": self.created_items['tile_sizes'][0],
            "vendor_name": "Test Vendor",
            "current_cost": 100.0
        }
        
        success, data, status = self.make_request('POST', 'tiles', tile_data)
        self.log_test("Create Tile", success, f"Status: {status}")
        
        if success and 'id' in data:
            tile_id = data['id']
            self.created_items['tiles'].append(tile_id)
            
            # Get all tiles
            success, data, status = self.make_request('GET', 'tiles')
            self.log_test("Get Tiles", success, f"Found {len(data) if isinstance(data, list) else 0} tiles")
            
            # Test status toggle
            success, data, status = self.make_request('PUT', f'tiles/{tile_id}/status')
            self.log_test("Toggle Tile Status", success, f"Status: {status}")
            
            return True
        return False

    def test_misc_items_crud(self):
        """Test misc items CRUD operations"""
        print("\n🔍 Testing Misc Items Management...")
        
        # Create misc item
        item_data = {
            "name": "Test Adhesive",
            "unit_label": "kg",
            "current_cost": 25.0,
            "description": "Test adhesive for tiles"
        }
        
        success, data, status = self.make_request('POST', 'misc-items', item_data)
        self.log_test("Create Misc Item", success, f"Status: {status}")
        
        if success and 'id' in data:
            item_id = data['id']
            self.created_items['misc_items'].append(item_id)
            
            # Get all misc items
            success, data, status = self.make_request('GET', 'misc-items')
            self.log_test("Get Misc Items", success, f"Found {len(data) if isinstance(data, list) else 0} items")
            
            # Test status toggle
            success, data, status = self.make_request('PUT', f'misc-items/{item_id}/status')
            self.log_test("Toggle Misc Item Status", success, f"Status: {status}")
            
            return True
        return False

    def test_purchase_entries(self):
        """Test purchase entries"""
        print("\n🔍 Testing Purchase Entries...")
        
        # Ensure we have items to purchase
        if not self.created_items['tiles']:
            self.test_tiles_crud()
        if not self.created_items['misc_items']:
            self.test_misc_items_crud()
        
        if not self.created_items['tiles'] and not self.created_items['misc_items']:
            self.log_test("Create Purchase Entry", False, "No items available")
            return False
        
        # Create purchase entry for tile
        if self.created_items['tiles']:
            purchase_data = {
                "item_id": self.created_items['tiles'][0],
                "item_type": "tile",
                "purchase_date": datetime.now(timezone.utc).isoformat(),
                "total_quantity": 10.0,
                "damage_percentage": 2.0,
                "cost_per_unit": 95.0,
                "transport_cost": 100.0,
                "supplier_name": "Test Supplier",
                "invoice_number": "TEST-001",
                "notes": "Test purchase entry"
            }
            
            success, data, status = self.make_request('POST', 'purchase-entries', purchase_data)
            self.log_test("Create Purchase Entry (Tile)", success, f"Status: {status}")
            
            if success and 'id' in data:
                self.created_items['purchase_entries'].append(data['id'])
        
        # Create purchase entry for misc item
        if self.created_items['misc_items']:
            purchase_data = {
                "item_id": self.created_items['misc_items'][0],
                "item_type": "misc",
                "purchase_date": datetime.now(timezone.utc).isoformat(),
                "total_quantity": 50.0,
                "damage_percentage": 0.0,
                "cost_per_unit": 24.0,
                "transport_cost": 50.0,
                "supplier_name": "Test Supplier",
                "invoice_number": "TEST-002",
                "notes": "Test misc purchase"
            }
            
            success, data, status = self.make_request('POST', 'purchase-entries', purchase_data)
            self.log_test("Create Purchase Entry (Misc)", success, f"Status: {status}")
        
        # Get purchase entries
        success, data, status = self.make_request('GET', 'purchase-entries')
        self.log_test("Get Purchase Entries", success, f"Found {len(data) if isinstance(data, list) else 0} entries")
        
        return True

    def test_quotations(self):
        """Test quotation management"""
        print("\n🔍 Testing Quotation Management...")
        
        # Create quotation
        quotation_data = {
            "customer_name": "Test Customer",
            "firm_name": "Test Firm Ltd",
            "phone": "9876543210",
            "customer_gst": "27ABCDE1234F1Z5",
            "notes": "Test quotation"
        }
        
        success, data, status = self.make_request('POST', 'quotations', quotation_data)
        self.log_test("Create Quotation", success, f"Status: {status}")
        
        if success and 'id' in data:
            quotation_id = data['id']
            self.created_items['quotations'].append(quotation_id)
            
            # Get quotation details
            success, data, status = self.make_request('GET', f'quotations/{quotation_id}')
            self.log_test("Get Quotation Details", success, f"Status: {status}")
            
            # Add item to quotation (if we have items with stock)
            if self.created_items['tiles']:
                item_data = {
                    "item_id": self.created_items['tiles'][0],
                    "item_type": "tile",
                    "purpose": "Test purpose",
                    "quantity": 2.0,
                    "rate_per_unit": 110.0
                }
                
                success, data, status = self.make_request('POST', f'quotations/{quotation_id}/items', item_data)
                self.log_test("Add Item to Quotation", success, f"Status: {status}")
                
                if success and 'id' in data:
                    item_id = data['id']
                    
                    # Delete quotation item
                    success, data, status = self.make_request('DELETE', f'quotations/{quotation_id}/items/{item_id}')
                    self.log_test("Delete Quotation Item", success, f"Status: {status}")
        
        # Get all quotations
        success, data, status = self.make_request('GET', 'quotations')
        self.log_test("Get All Quotations", success, f"Found {len(data) if isinstance(data, list) else 0} quotations")
        
        return True

    def test_reports(self):
        """Test reporting endpoints"""
        print("\n🔍 Testing Reports...")
        
        # Inventory report
        success, data, status = self.make_request('GET', 'reports/inventory')
        self.log_test("Inventory Report", success, f"Status: {status}")
        
        if success and isinstance(data, dict):
            summary = data.get('summary', {})
            tiles_count = len(data.get('tiles', []))
            misc_count = len(data.get('misc_items', []))
            self.log_test("Inventory Report Content", True, 
                         f"Tiles: {tiles_count}, Misc: {misc_count}, Total Value: ₹{summary.get('total_inventory_value', 0)}")
        
        # Sales report
        success, data, status = self.make_request('GET', 'reports/sales', params={'days': 30})
        self.log_test("Sales Report", success, f"Status: {status}")
        
        if success and isinstance(data, dict):
            quotations_count = data.get('total_quotations', 0)
            total_value = data.get('total_quotation_value', 0)
            self.log_test("Sales Report Content", True, 
                         f"Quotations: {quotations_count}, Total Value: ₹{total_value}")
        
        return True

    def test_stock_calculations(self):
        """Test stock calculation accuracy"""
        print("\n🔍 Testing Stock Calculations...")
        
        # Get tiles with stock info
        success, data, status = self.make_request('GET', 'tiles')
        if success and isinstance(data, list):
            for tile in data:
                if tile.get('current_stock', 0) > 0:
                    stock = tile.get('current_stock', 0)
                    value = tile.get('stock_value', 0)
                    avg_cost = tile.get('average_cost', 0)
                    
                    # Verify stock value calculation
                    expected_value = stock * avg_cost
                    if abs(value - expected_value) < 0.01:  # Allow small floating point differences
                        self.log_test(f"Stock Calculation for {tile['name']}", True, 
                                     f"Stock: {stock:.2f}, Value: ₹{value:.2f}")
                    else:
                        self.log_test(f"Stock Calculation for {tile['name']}", False, 
                                     f"Expected: ₹{expected_value:.2f}, Got: ₹{value:.2f}")
        
        return True

    def run_all_tests(self):
        """Run comprehensive test suite"""
        print("🚀 Starting Tile Inventory API Test Suite")
        print("=" * 60)
        
        # Test sequence
        tests = [
            self.test_api_health,
            self.test_tile_sizes_crud,
            self.test_tiles_crud,
            self.test_misc_items_crud,
            self.test_purchase_entries,
            self.test_quotations,
            self.test_reports,
            self.test_stock_calculations
        ]
        
        for test in tests:
            try:
                test()
            except Exception as e:
                print(f"❌ Test {test.__name__} failed with exception: {str(e)}")
        
        # Print summary
        print("\n" + "=" * 60)
        print(f"📊 Test Results: {self.tests_passed}/{self.tests_run} tests passed")
        print(f"✅ Success Rate: {(self.tests_passed/self.tests_run*100):.1f}%")
        
        if self.tests_passed == self.tests_run:
            print("🎉 All tests passed! Backend API is fully functional.")
            return 0
        else:
            print("⚠️  Some tests failed. Check the details above.")
            return 1

def main():
    tester = TileInventoryAPITester()
    return tester.run_all_tests()

if __name__ == "__main__":
    sys.exit(main())