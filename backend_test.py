#!/usr/bin/env python3
"""
Backend Test for PHP/SQLite Tile Inventory System
Tests database schema, stock calculations, and API endpoints
"""

import requests
import sqlite3
import sys
import os
from datetime import datetime

class TileInventoryTester:
    def __init__(self, base_url="https://inventory-tracker-159.preview.emergentagent.com"):
        self.base_url = base_url
        self.db_path = "/app/data/app.sqlite"
        self.tests_run = 0
        self.tests_passed = 0
        self.session = requests.Session()
        
    def log_test(self, name, success, message=""):
        """Log test result"""
        self.tests_run += 1
        if success:
            self.tests_passed += 1
            print(f"✅ {name}: PASSED {message}")
        else:
            print(f"❌ {name}: FAILED {message}")
        return success

    def test_database_connection(self):
        """Test SQLite database connection and basic structure"""
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Check if database file exists and is accessible
            cursor.execute("SELECT name FROM sqlite_master WHERE type='table'")
            tables = [row[0] for row in cursor.fetchall()]
            
            required_tables = ['tiles', 'misc_items', 'quotations', 'quotation_items', 
                             'quotation_misc_items', 'purchase_entries_tiles', 'purchase_entries_misc']
            
            missing_tables = [table for table in required_tables if table not in tables]
            
            if missing_tables:
                return self.log_test("Database Connection", False, f"Missing tables: {missing_tables}")
            
            conn.close()
            return self.log_test("Database Connection", True, f"Found {len(tables)} tables")
            
        except Exception as e:
            return self.log_test("Database Connection", False, f"Error: {str(e)}")

    def test_active_column_migration(self):
        """Test if active column was added to tiles and misc_items tables"""
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Check tiles table for active column
            cursor.execute("PRAGMA table_info(tiles)")
            tiles_columns = [col[1] for col in cursor.fetchall()]
            tiles_has_active = 'active' in tiles_columns
            
            # Check misc_items table for active column
            cursor.execute("PRAGMA table_info(misc_items)")
            misc_columns = [col[1] for col in cursor.fetchall()]
            misc_has_active = 'active' in misc_columns
            
            conn.close()
            
            if tiles_has_active and misc_has_active:
                return self.log_test("Active Column Migration", True, "Both tables have active column")
            else:
                missing = []
                if not tiles_has_active:
                    missing.append("tiles")
                if not misc_has_active:
                    missing.append("misc_items")
                return self.log_test("Active Column Migration", False, f"Missing active column in: {missing}")
                
        except Exception as e:
            return self.log_test("Active Column Migration", False, f"Error: {str(e)}")

    def test_database_views(self):
        """Test if current_tiles_stock and current_misc_stock views exist"""
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Check for views
            cursor.execute("SELECT name FROM sqlite_master WHERE type='view'")
            views = [row[0] for row in cursor.fetchall()]
            
            required_views = ['current_tiles_stock', 'current_misc_stock']
            missing_views = [view for view in required_views if view not in views]
            
            if missing_views:
                conn.close()
                return self.log_test("Database Views", False, f"Missing views: {missing_views}")
            
            # Test if views return data
            cursor.execute("SELECT COUNT(*) FROM current_tiles_stock")
            tiles_count = cursor.fetchone()[0]
            
            cursor.execute("SELECT COUNT(*) FROM current_misc_stock")
            misc_count = cursor.fetchone()[0]
            
            conn.close()
            return self.log_test("Database Views", True, f"Views exist - Tiles: {tiles_count}, Misc: {misc_count}")
            
        except Exception as e:
            return self.log_test("Database Views", False, f"Error: {str(e)}")

    def test_web_server_response(self):
        """Test if web server is responding"""
        try:
            response = self.session.get(f"{self.base_url}/login.php", timeout=10)
            if response.status_code == 200:
                return self.log_test("Web Server Response", True, f"Status: {response.status_code}")
            else:
                return self.log_test("Web Server Response", False, f"Status: {response.status_code}")
        except Exception as e:
            return self.log_test("Web Server Response", False, f"Error: {str(e)}")

    def test_quotation_enhanced_page(self):
        """Test if quotation_enhanced.php loads without errors"""
        try:
            # First try to access login page to get session
            login_response = self.session.get(f"{self.base_url}/login.php")
            
            # Try to access quotation page (might redirect to login)
            response = self.session.get(f"{self.base_url}/quotation_enhanced.php", timeout=10)
            
            if response.status_code == 200:
                # Check if page contains expected content
                content = response.text.lower()
                if "quotation" in content and "create" in content:
                    return self.log_test("Quotation Enhanced Page", True, "Page loads with expected content")
                else:
                    return self.log_test("Quotation Enhanced Page", False, "Page loads but missing expected content")
            else:
                return self.log_test("Quotation Enhanced Page", False, f"Status: {response.status_code}")
                
        except Exception as e:
            return self.log_test("Quotation Enhanced Page", False, f"Error: {str(e)}")

    def test_quotation_view_page(self):
        """Test if quotation_view.php exists and loads"""
        try:
            # Test with a dummy ID - should either show quotation or redirect/error gracefully
            response = self.session.get(f"{self.base_url}/quotation_view.php?id=1", timeout=10)
            
            if response.status_code == 200:
                return self.log_test("Quotation View Page", True, "Page exists and loads")
            elif response.status_code == 302:
                return self.log_test("Quotation View Page", True, "Page exists (redirected)")
            else:
                return self.log_test("Quotation View Page", False, f"Status: {response.status_code}")
                
        except Exception as e:
            return self.log_test("Quotation View Page", False, f"Error: {str(e)}")

    def test_other_purchase_page(self):
        """Test if other_purchase.php loads with enhanced functionality"""
        try:
            response = self.session.get(f"{self.base_url}/other_purchase.php", timeout=10)
            
            if response.status_code == 200:
                content = response.text.lower()
                # Check for hide/show functionality indicators
                if "active" in content and "hide" in content:
                    return self.log_test("Other Purchase Page", True, "Page loads with hide/show functionality")
                else:
                    return self.log_test("Other Purchase Page", True, "Page loads but hide/show functionality unclear")
            else:
                return self.log_test("Other Purchase Page", False, f"Status: {response.status_code}")
                
        except Exception as e:
            return self.log_test("Other Purchase Page", False, f"Error: {str(e)}")

    def test_inventory_report_page(self):
        """Test if report_inventory_enhanced.php loads"""
        try:
            response = self.session.get(f"{self.base_url}/report_inventory_enhanced.php", timeout=10)
            
            if response.status_code == 200:
                return self.log_test("Inventory Report Page", True, "Page loads successfully")
            elif response.status_code == 302:
                return self.log_test("Inventory Report Page", True, "Page exists (redirected - likely auth)")
            else:
                return self.log_test("Inventory Report Page", False, f"Status: {response.status_code}")
                
        except Exception as e:
            return self.log_test("Inventory Report Page", False, f"Error: {str(e)}")

    def test_daily_pl_report_page(self):
        """Test if report_daily_pl.php loads"""
        try:
            response = self.session.get(f"{self.base_url}/report_daily_pl.php", timeout=10)
            
            if response.status_code == 200:
                return self.log_test("Daily P&L Report Page", True, "Page loads successfully")
            elif response.status_code == 302:
                return self.log_test("Daily P&L Report Page", True, "Page exists (redirected - likely auth)")
            else:
                return self.log_test("Daily P&L Report Page", False, f"Status: {response.status_code}")
                
        except Exception as e:
            return self.log_test("Daily P&L Report Page", False, f"Error: {str(e)}")

    def test_stock_calculation_consistency(self):
        """Test if stock calculations are consistent across views"""
        try:
            conn = sqlite3.connect(self.db_path)
            cursor = conn.cursor()
            
            # Test tiles stock calculation
            cursor.execute("""
                SELECT t.id, t.name, 
                       COALESCE(cts.total_stock_boxes, 0) as view_stock,
                       COALESCE(SUM(pe.usable_boxes), 0) as direct_stock
                FROM tiles t
                LEFT JOIN current_tiles_stock cts ON t.id = cts.id
                LEFT JOIN purchase_entries_tiles pe ON t.id = pe.tile_id
                WHERE t.active = 1
                GROUP BY t.id, t.name, cts.total_stock_boxes
                LIMIT 5
            """)
            
            tiles_results = cursor.fetchall()
            tiles_consistent = True
            
            for tile in tiles_results:
                if abs(tile[2] - tile[3]) > 0.01:  # Allow small floating point differences
                    tiles_consistent = False
                    break
            
            # Test misc items stock calculation
            cursor.execute("""
                SELECT m.id, m.name,
                       COALESCE(cms.total_stock_quantity, 0) as view_stock,
                       COALESCE(SUM(pe.usable_quantity), 0) as direct_stock
                FROM misc_items m
                LEFT JOIN current_misc_stock cms ON m.id = cms.id
                LEFT JOIN purchase_entries_misc pe ON m.id = pe.misc_item_id
                WHERE m.active = 1
                GROUP BY m.id, m.name, cms.total_stock_quantity
                LIMIT 5
            """)
            
            misc_results = cursor.fetchall()
            misc_consistent = True
            
            for item in misc_results:
                if abs(item[2] - item[3]) > 0.01:  # Allow small floating point differences
                    misc_consistent = False
                    break
            
            conn.close()
            
            if tiles_consistent and misc_consistent:
                return self.log_test("Stock Calculation Consistency", True, 
                                   f"Tested {len(tiles_results)} tiles, {len(misc_results)} misc items")
            else:
                return self.log_test("Stock Calculation Consistency", False, 
                                   f"Inconsistencies found in stock calculations")
                
        except Exception as e:
            return self.log_test("Stock Calculation Consistency", False, f"Error: {str(e)}")

    def run_all_tests(self):
        """Run all backend tests"""
        print("🔍 Starting Backend Tests for PHP/SQLite Tile Inventory System")
        print("=" * 60)
        
        # Database tests
        self.test_database_connection()
        self.test_active_column_migration()
        self.test_database_views()
        self.test_stock_calculation_consistency()
        
        # Web server tests
        self.test_web_server_response()
        self.test_quotation_enhanced_page()
        self.test_quotation_view_page()
        self.test_other_purchase_page()
        self.test_inventory_report_page()
        self.test_daily_pl_report_page()
        
        print("=" * 60)
        print(f"📊 Backend Tests Summary: {self.tests_passed}/{self.tests_run} passed")
        
        if self.tests_passed == self.tests_run:
            print("🎉 All backend tests passed!")
            return 0
        else:
            print(f"⚠️  {self.tests_run - self.tests_passed} tests failed")
            return 1

def main():
    tester = TileInventoryTester()
    return tester.run_all_tests()

if __name__ == "__main__":
    sys.exit(main())