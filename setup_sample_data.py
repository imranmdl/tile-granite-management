#!/usr/bin/env python3
"""
Setup sample data for tile inventory system
"""
import asyncio
import os
from motor.motor_asyncio import AsyncIOMotorClient
from datetime import datetime, timezone

# MongoDB connection
MONGO_URL = os.environ.get('MONGO_URL', 'mongodb://localhost:27017')
DB_NAME = os.environ.get('DB_NAME', 'tile_inventory')

async def setup_sample_data():
    client = AsyncIOMotorClient(MONGO_URL)
    db = client[DB_NAME]
    
    # Clear existing data
    await db.tile_sizes.delete_many({})
    await db.tiles.delete_many({})
    await db.misc_items.delete_many({})
    await db.purchase_entries.delete_many({})
    await db.quotations.delete_many({})
    await db.quotation_items.delete_many({})
    
    print("Creating sample tile sizes...")
    tile_sizes = [
        {
            "id": "size1",
            "name": "12x12 inches",
            "length_inches": 12,
            "width_inches": 12,
            "sqft_per_box": 8.0,
            "created_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "size2", 
            "name": "24x24 inches",
            "length_inches": 24,
            "width_inches": 24,
            "sqft_per_box": 16.0,
            "created_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "size3",
            "name": "18x18 inches", 
            "length_inches": 18,
            "width_inches": 18,
            "sqft_per_box": 12.0,
            "created_at": datetime.now(timezone.utc).isoformat()
        }
    ]
    await db.tile_sizes.insert_many(tile_sizes)
    
    print("Creating sample tiles...")
    tiles = [
        {
            "id": "tile1",
            "name": "Marble White Classic",
            "size_id": "size1",
            "vendor_name": "Premium Tiles Co",
            "current_cost": 450.0,
            "photo_path": None,
            "status": "active",
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "tile2", 
            "name": "Granite Black Premium",
            "size_id": "size2",
            "vendor_name": "Stone Masters Ltd",
            "current_cost": 680.0,
            "photo_path": None,
            "status": "active",
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "tile3",
            "name": "Ceramic Wood Look",
            "size_id": "size3", 
            "vendor_name": "Modern Ceramics",
            "current_cost": 520.0,
            "photo_path": None,
            "status": "active",
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        }
    ]
    await db.tiles.insert_many(tiles)
    
    print("Creating sample misc items...")
    misc_items = [
        {
            "id": "misc1",
            "name": "Tile Adhesive",
            "unit_label": "kg",
            "current_cost": 25.0,
            "description": "High strength tile adhesive",
            "photo_path": None,
            "status": "active",
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "misc2",
            "name": "Grout White",
            "unit_label": "kg", 
            "current_cost": 18.0,
            "description": "White tile grout for spacing",
            "photo_path": None,
            "status": "active",
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "misc3",
            "name": "Tile Spacers",
            "unit_label": "pack",
            "current_cost": 12.0,
            "description": "Plastic tile spacers 3mm",
            "photo_path": None,
            "status": "active", 
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        }
    ]
    await db.misc_items.insert_many(misc_items)
    
    print("Creating sample purchase entries...")
    purchase_entries = [
        {
            "id": "purchase1",
            "item_id": "tile1",
            "item_type": "tile",
            "purchase_date": datetime.now(timezone.utc).isoformat(),
            "total_quantity": 50.0,
            "damage_percentage": 2.0,
            "cost_per_unit": 420.0,
            "transport_cost": 500.0,
            "supplier_name": "Premium Tiles Co",
            "invoice_number": "INV-2024-001",
            "notes": "First batch of marble tiles",
            "created_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "purchase2",
            "item_id": "tile2", 
            "item_type": "tile",
            "purchase_date": datetime.now(timezone.utc).isoformat(),
            "total_quantity": 30.0,
            "damage_percentage": 1.0,
            "cost_per_unit": 650.0,
            "transport_cost": 400.0,
            "supplier_name": "Stone Masters Ltd",
            "invoice_number": "INV-2024-002", 
            "notes": "Premium granite tiles",
            "created_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "purchase3",
            "item_id": "misc1",
            "item_type": "misc",
            "purchase_date": datetime.now(timezone.utc).isoformat(),
            "total_quantity": 100.0,
            "damage_percentage": 0.0,
            "cost_per_unit": 23.0,
            "transport_cost": 150.0,
            "supplier_name": "Adhesive Suppliers",
            "invoice_number": "ADH-001",
            "notes": "Bulk adhesive purchase",
            "created_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "purchase4",
            "item_id": "misc2",
            "item_type": "misc", 
            "purchase_date": datetime.now(timezone.utc).isoformat(),
            "total_quantity": 80.0,
            "damage_percentage": 0.0,
            "cost_per_unit": 16.0,
            "transport_cost": 80.0,
            "supplier_name": "Grout Pro",
            "invoice_number": "GRT-001",
            "notes": "White grout supply",
            "created_at": datetime.now(timezone.utc).isoformat()
        }
    ]
    await db.purchase_entries.insert_many(purchase_entries)
    
    print("Creating sample quotations...")
    quotations = [
        {
            "id": "quote1",
            "quote_no": "Q241010001",
            "quote_date": datetime.now(timezone.utc).isoformat(),
            "customer_name": "John Smith",
            "firm_name": "Smith Constructions",
            "phone": "9876543210",
            "customer_gst": "27ABCDE1234F1Z5",
            "notes": "Bathroom renovation project",
            "total": 25000.0,
            "discount_amount": 1000.0,
            "final_total": 24000.0,
            "status": "draft",
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "quote2",
            "quote_no": "Q241010002",
            "quote_date": datetime.now(timezone.utc).isoformat(),
            "customer_name": "Alice Johnson",
            "firm_name": "Modern Homes Ltd",
            "phone": "8765432109",
            "customer_gst": None,
            "notes": "Kitchen flooring project",
            "total": 18500.0,
            "discount_amount": 0.0,
            "final_total": 18500.0,
            "status": "draft", 
            "created_at": datetime.now(timezone.utc).isoformat(),
            "updated_at": datetime.now(timezone.utc).isoformat()
        }
    ]
    await db.quotations.insert_many(quotations)
    
    print("Creating sample quotation items...")
    quotation_items = [
        {
            "id": "qitem1",
            "quotation_id": "quote1",
            "item_id": "tile1", 
            "item_type": "tile",
            "item_name": "Marble White Classic",
            "purpose": "Bathroom floor",
            "quantity": 25.0,
            "rate_per_unit": 480.0,
            "line_total": 12000.0,
            "created_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "qitem2",
            "quotation_id": "quote1",
            "item_id": "misc1",
            "item_type": "misc",
            "item_name": "Tile Adhesive",
            "purpose": "Installation",
            "quantity": 50.0,
            "rate_per_unit": 28.0,
            "line_total": 1400.0,
            "created_at": datetime.now(timezone.utc).isoformat()
        },
        {
            "id": "qitem3",
            "quotation_id": "quote2",
            "item_id": "tile2",
            "item_type": "tile", 
            "item_name": "Granite Black Premium",
            "purpose": "Kitchen floor",
            "quantity": 20.0,
            "rate_per_unit": 720.0,
            "line_total": 14400.0,
            "created_at": datetime.now(timezone.utc).isoformat()
        }
    ]
    await db.quotation_items.insert_many(quotation_items)
    
    print("Sample data setup completed!")
    print("\n=== CREATED DATA SUMMARY ===")
    print(f"Tile Sizes: {len(tile_sizes)}")
    print(f"Tiles: {len(tiles)}")
    print(f"Misc Items: {len(misc_items)}")
    print(f"Purchase Entries: {len(purchase_entries)}")
    print(f"Quotations: {len(quotations)}")
    print(f"Quotation Items: {len(quotation_items)}")
    
    client.close()

if __name__ == "__main__":
    asyncio.run(setup_sample_data())