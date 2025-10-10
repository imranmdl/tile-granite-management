from fastapi import FastAPI, APIRouter, HTTPException, Query
from dotenv import load_dotenv
from starlette.middleware.cors import CORSMiddleware
from motor.motor_asyncio import AsyncIOMotorClient
import os
import logging
from pathlib import Path
from pydantic import BaseModel, Field, ConfigDict
from typing import List, Optional, Dict, Any
import uuid
from datetime import datetime, timezone
from enum import Enum

ROOT_DIR = Path(__file__).parent
load_dotenv(ROOT_DIR / '.env')

# MongoDB connection
mongo_url = os.environ['MONGO_URL']
client = AsyncIOMotorClient(mongo_url)
db = client[os.environ.get('DB_NAME', 'tile_inventory')]

# Create the main app without a prefix
app = FastAPI(title="Tile Inventory Management System")

# Create a router with the /api prefix
api_router = APIRouter(prefix="/api")

# Models
class ItemStatus(str, Enum):
    ACTIVE = "active"
    INACTIVE = "inactive"

class TileSize(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    name: str
    length_inches: float
    width_inches: float
    sqft_per_box: float
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class TileSizeCreate(BaseModel):
    name: str
    length_inches: float
    width_inches: float
    sqft_per_box: float

class Tile(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    name: str
    size_id: str
    vendor_name: Optional[str] = None
    current_cost: float = 0.0
    photo_path: Optional[str] = None
    status: ItemStatus = ItemStatus.ACTIVE
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    updated_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class TileCreate(BaseModel):
    name: str
    size_id: str
    vendor_name: Optional[str] = None
    current_cost: float = 0.0
    photo_path: Optional[str] = None

class MiscItem(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    name: str
    unit_label: str = "unit"
    current_cost: float = 0.0
    description: Optional[str] = None
    photo_path: Optional[str] = None
    status: ItemStatus = ItemStatus.ACTIVE
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    updated_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class MiscItemCreate(BaseModel):
    name: str
    unit_label: str = "unit"
    current_cost: float = 0.0
    description: Optional[str] = None
    photo_path: Optional[str] = None

class PurchaseEntry(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    item_id: str
    item_type: str  # "tile" or "misc"
    purchase_date: datetime
    total_quantity: float
    damage_percentage: float = 0.0
    cost_per_unit: float
    transport_cost: float = 0.0
    supplier_name: Optional[str] = None
    invoice_number: Optional[str] = None
    notes: Optional[str] = None
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class PurchaseEntryCreate(BaseModel):
    item_id: str
    item_type: str
    purchase_date: datetime
    total_quantity: float
    damage_percentage: float = 0.0
    cost_per_unit: float
    transport_cost: float = 0.0
    supplier_name: Optional[str] = None
    invoice_number: Optional[str] = None
    notes: Optional[str] = None

class Quotation(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    quote_no: str
    quote_date: datetime
    customer_name: str
    firm_name: Optional[str] = None
    phone: str
    customer_gst: Optional[str] = None
    notes: Optional[str] = None
    total: float = 0.0
    discount_amount: float = 0.0
    final_total: float = 0.0
    status: str = "draft"  # draft, sent, accepted, rejected
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))
    updated_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class QuotationCreate(BaseModel):
    customer_name: str
    firm_name: Optional[str] = None
    phone: str
    customer_gst: Optional[str] = None
    notes: Optional[str] = None

class QuotationItem(BaseModel):
    model_config = ConfigDict(extra="ignore")
    id: str = Field(default_factory=lambda: str(uuid.uuid4()))
    quotation_id: str
    item_id: str
    item_type: str  # "tile" or "misc"
    item_name: str
    purpose: Optional[str] = None
    quantity: float
    rate_per_unit: float
    line_total: float
    created_at: datetime = Field(default_factory=lambda: datetime.now(timezone.utc))

class QuotationItemCreate(BaseModel):
    item_id: str
    item_type: str
    purpose: Optional[str] = None
    quantity: float
    rate_per_unit: float

class StockSummary(BaseModel):
    item_id: str
    item_name: str
    item_type: str
    current_stock: float
    total_purchased: float
    average_cost: float
    stock_value: float

# Helper Functions
async def calculate_stock_for_item(item_id: str, item_type: str) -> Dict[str, float]:
    """Calculate current stock for an item based on purchase entries"""
    purchases = await db.purchase_entries.find({"item_id": item_id, "item_type": item_type}).to_list(None)
    
    total_purchased = 0.0
    total_cost = 0.0
    total_transport = 0.0
    
    for purchase in purchases:
        usable_qty = purchase['total_quantity'] * (1 - purchase['damage_percentage'] / 100)
        total_purchased += usable_qty
        total_cost += purchase['total_quantity'] * purchase['cost_per_unit']
        total_transport += purchase.get('transport_cost', 0.0)
    
    # Get total sold (simplified - in real system would check invoices)
    # For now, return total purchased as current stock
    current_stock = total_purchased
    
    average_cost = (total_cost + total_transport) / total_purchased if total_purchased > 0 else 0.0
    stock_value = current_stock * average_cost
    
    return {
        "current_stock": current_stock,
        "total_purchased": total_purchased,
        "average_cost": average_cost,
        "stock_value": stock_value
    }

# API Routes
@api_router.get("/")
async def root():
    return {"message": "Tile Inventory Management System API"}

# Tile Size Management
@api_router.post("/tile-sizes", response_model=TileSize)
async def create_tile_size(size: TileSizeCreate):
    size_dict = size.model_dump()
    size_obj = TileSize(**size_dict)
    
    doc = size_obj.model_dump()
    doc['created_at'] = doc['created_at'].isoformat()
    
    await db.tile_sizes.insert_one(doc)
    return size_obj

@api_router.get("/tile-sizes", response_model=List[TileSize])
async def get_tile_sizes():
    sizes = await db.tile_sizes.find({}, {"_id": 0}).to_list(1000)
    for size in sizes:
        if isinstance(size['created_at'], str):
            size['created_at'] = datetime.fromisoformat(size['created_at'])
    return sizes

# Tile Management
@api_router.post("/tiles", response_model=Tile)
async def create_tile(tile: TileCreate):
    # Verify size exists
    size = await db.tile_sizes.find_one({"id": tile.size_id}, {"_id": 0})
    if not size:
        raise HTTPException(status_code=400, detail="Tile size not found")
    
    tile_dict = tile.model_dump()
    tile_obj = Tile(**tile_dict)
    
    doc = tile_obj.model_dump()
    doc['created_at'] = doc['created_at'].isoformat()
    doc['updated_at'] = doc['updated_at'].isoformat()
    
    await db.tiles.insert_one(doc)
    return tile_obj

@api_router.get("/tiles", response_model=List[Dict[str, Any]])
async def get_tiles(active_only: bool = Query(True, description="Show only active tiles")):
    query = {"status": "active"} if active_only else {}
    tiles = await db.tiles.find(query, {"_id": 0}).to_list(1000)
    
    # Enrich with size info and stock
    for tile in tiles:
        if isinstance(tile['created_at'], str):
            tile['created_at'] = datetime.fromisoformat(tile['created_at'])
        if isinstance(tile['updated_at'], str):
            tile['updated_at'] = datetime.fromisoformat(tile['updated_at'])
            
        # Get size info
        size = await db.tile_sizes.find_one({"id": tile['size_id']}, {"_id": 0})
        tile['size_info'] = size
        
        # Get stock info
        stock_info = await calculate_stock_for_item(tile['id'], "tile")
        tile.update(stock_info)
    
    return tiles

@api_router.put("/tiles/{tile_id}/status")
async def toggle_tile_status(tile_id: str):
    tile = await db.tiles.find_one({"id": tile_id}, {"_id": 0})
    if not tile:
        raise HTTPException(status_code=404, detail="Tile not found")
    
    new_status = "inactive" if tile['status'] == "active" else "active"
    
    await db.tiles.update_one(
        {"id": tile_id},
        {"$set": {"status": new_status, "updated_at": datetime.now(timezone.utc).isoformat()}}
    )
    
    return {"message": f"Tile status updated to {new_status}", "new_status": new_status}

# Misc Item Management
@api_router.post("/misc-items", response_model=MiscItem)
async def create_misc_item(item: MiscItemCreate):
    item_dict = item.model_dump()
    item_obj = MiscItem(**item_dict)
    
    doc = item_obj.model_dump()
    doc['created_at'] = doc['created_at'].isoformat()
    doc['updated_at'] = doc['updated_at'].isoformat()
    
    await db.misc_items.insert_one(doc)
    return item_obj

@api_router.get("/misc-items", response_model=List[Dict[str, Any]])
async def get_misc_items(active_only: bool = Query(True, description="Show only active items")):
    query = {"status": "active"} if active_only else {}
    items = await db.misc_items.find(query, {"_id": 0}).to_list(1000)
    
    # Enrich with stock info
    for item in items:
        if isinstance(item['created_at'], str):
            item['created_at'] = datetime.fromisoformat(item['created_at'])
        if isinstance(item['updated_at'], str):
            item['updated_at'] = datetime.fromisoformat(item['updated_at'])
            
        # Get stock info
        stock_info = await calculate_stock_for_item(item['id'], "misc")
        item.update(stock_info)
    
    return items

@api_router.put("/misc-items/{item_id}/status")
async def toggle_misc_item_status(item_id: str):
    item = await db.misc_items.find_one({"id": item_id}, {"_id": 0})
    if not item:
        raise HTTPException(status_code=404, detail="Item not found")
    
    new_status = "inactive" if item['status'] == "active" else "active"
    
    await db.misc_items.update_one(
        {"id": item_id},
        {"$set": {"status": new_status, "updated_at": datetime.now(timezone.utc).isoformat()}}
    )
    
    return {"message": f"Item status updated to {new_status}", "new_status": new_status}

# Purchase Entry Management
@api_router.post("/purchase-entries", response_model=PurchaseEntry)
async def create_purchase_entry(entry: PurchaseEntryCreate):
    # Verify item exists
    if entry.item_type == "tile":
        item = await db.tiles.find_one({"id": entry.item_id}, {"_id": 0})
    else:
        item = await db.misc_items.find_one({"id": entry.item_id}, {"_id": 0})
    
    if not item:
        raise HTTPException(status_code=400, detail="Item not found")
    
    entry_dict = entry.model_dump()
    entry_obj = PurchaseEntry(**entry_dict)
    
    doc = entry_obj.model_dump()
    doc['purchase_date'] = doc['purchase_date'].isoformat()
    doc['created_at'] = doc['created_at'].isoformat()
    
    await db.purchase_entries.insert_one(doc)
    return entry_obj

@api_router.get("/purchase-entries", response_model=List[Dict[str, Any]])
async def get_purchase_entries(item_type: Optional[str] = None, limit: int = Query(50, le=200)):
    query = {}
    if item_type:
        query["item_type"] = item_type
    
    entries = await db.purchase_entries.find(query, {"_id": 0}).sort("created_at", -1).limit(limit).to_list(None)
    
    # Enrich with item info
    for entry in entries:
        if isinstance(entry['purchase_date'], str):
            entry['purchase_date'] = datetime.fromisoformat(entry['purchase_date'])
        if isinstance(entry['created_at'], str):
            entry['created_at'] = datetime.fromisoformat(entry['created_at'])
            
        # Get item info
        if entry['item_type'] == "tile":
            item = await db.tiles.find_one({"id": entry['item_id']}, {"_id": 0})
        else:
            item = await db.misc_items.find_one({"id": entry['item_id']}, {"_id": 0})
        
        entry['item_info'] = item
        
        # Calculate derived values
        usable_qty = entry['total_quantity'] * (1 - entry['damage_percentage'] / 100)
        material_cost = entry['total_quantity'] * entry['cost_per_unit']
        total_cost = material_cost + entry.get('transport_cost', 0.0)
        
        entry['usable_quantity'] = usable_qty
        entry['material_cost'] = material_cost
        entry['total_cost'] = total_cost
    
    return entries

# Quotation Management
@api_router.post("/quotations", response_model=Quotation)
async def create_quotation(quotation: QuotationCreate):
    quotation_dict = quotation.model_dump()
    # Generate quote number
    quotation_dict['quote_no'] = f"Q{datetime.now().strftime('%y%m%d%H%M%S')}"
    quotation_dict['quote_date'] = datetime.now(timezone.utc)
    
    quotation_obj = Quotation(**quotation_dict)
    
    doc = quotation_obj.model_dump()
    doc['quote_date'] = doc['quote_date'].isoformat()
    doc['created_at'] = doc['created_at'].isoformat()
    doc['updated_at'] = doc['updated_at'].isoformat()
    
    await db.quotations.insert_one(doc)
    return quotation_obj

@api_router.get("/quotations", response_model=List[Quotation])
async def get_quotations(limit: int = Query(50, le=200)):
    quotations = await db.quotations.find({}, {"_id": 0}).sort("created_at", -1).limit(limit).to_list(None)
    
    for quotation in quotations:
        if isinstance(quotation['quote_date'], str):
            quotation['quote_date'] = datetime.fromisoformat(quotation['quote_date'])
        if isinstance(quotation['created_at'], str):
            quotation['created_at'] = datetime.fromisoformat(quotation['created_at'])
        if isinstance(quotation['updated_at'], str):
            quotation['updated_at'] = datetime.fromisoformat(quotation['updated_at'])
    
    return quotations

@api_router.get("/quotations/{quotation_id}", response_model=Dict[str, Any])
async def get_quotation_details(quotation_id: str):
    quotation = await db.quotations.find_one({"id": quotation_id}, {"_id": 0})
    if not quotation:
        raise HTTPException(status_code=404, detail="Quotation not found")
    
    # Convert dates
    if isinstance(quotation['quote_date'], str):
        quotation['quote_date'] = datetime.fromisoformat(quotation['quote_date'])
    if isinstance(quotation['created_at'], str):
        quotation['created_at'] = datetime.fromisoformat(quotation['created_at'])
    if isinstance(quotation['updated_at'], str):
        quotation['updated_at'] = datetime.fromisoformat(quotation['updated_at'])
    
    # Get quotation items
    items = await db.quotation_items.find({"quotation_id": quotation_id}, {"_id": 0}).to_list(None)
    for item in items:
        if isinstance(item['created_at'], str):
            item['created_at'] = datetime.fromisoformat(item['created_at'])
            
        # Get item details
        if item['item_type'] == "tile":
            item_details = await db.tiles.find_one({"id": item['item_id']}, {"_id": 0})
            if item_details:
                size_info = await db.tile_sizes.find_one({"id": item_details['size_id']}, {"_id": 0})
                item_details['size_info'] = size_info
        else:
            item_details = await db.misc_items.find_one({"id": item['item_id']}, {"_id": 0})
        
        item['item_details'] = item_details
    
    quotation['items'] = items
    return quotation

@api_router.post("/quotations/{quotation_id}/items", response_model=QuotationItem)
async def add_quotation_item(quotation_id: str, item: QuotationItemCreate):
    # Verify quotation exists
    quotation = await db.quotations.find_one({"id": quotation_id}, {"_id": 0})
    if not quotation:
        raise HTTPException(status_code=404, detail="Quotation not found")
    
    # Get item name
    if item.item_type == "tile":
        item_doc = await db.tiles.find_one({"id": item.item_id}, {"_id": 0})
    else:
        item_doc = await db.misc_items.find_one({"id": item.item_id}, {"_id": 0})
    
    if not item_doc:
        raise HTTPException(status_code=400, detail="Item not found")
    
    # Check stock availability
    stock_info = await calculate_stock_for_item(item.item_id, item.item_type)
    if item.quantity > stock_info['current_stock']:
        raise HTTPException(
            status_code=400, 
            detail=f"Insufficient stock. Available: {stock_info['current_stock']}, Requested: {item.quantity}"
        )
    
    item_dict = item.model_dump()
    item_dict['quotation_id'] = quotation_id
    item_dict['item_name'] = item_doc['name']
    item_dict['line_total'] = item.quantity * item.rate_per_unit
    
    item_obj = QuotationItem(**item_dict)
    
    doc = item_obj.model_dump()
    doc['created_at'] = doc['created_at'].isoformat()
    
    await db.quotation_items.insert_one(doc)
    
    # Update quotation total
    await update_quotation_total(quotation_id)
    
    return item_obj

@api_router.delete("/quotations/{quotation_id}/items/{item_id}")
async def delete_quotation_item(quotation_id: str, item_id: str):
    result = await db.quotation_items.delete_one({"id": item_id, "quotation_id": quotation_id})
    if result.deleted_count == 0:
        raise HTTPException(status_code=404, detail="Item not found")
    
    # Update quotation total
    await update_quotation_total(quotation_id)
    
    return {"message": "Item deleted successfully"}

async def update_quotation_total(quotation_id: str):
    """Helper function to update quotation total"""
    items = await db.quotation_items.find({"quotation_id": quotation_id}, {"_id": 0}).to_list(None)
    total = sum(item['line_total'] for item in items)
    
    quotation = await db.quotations.find_one({"id": quotation_id}, {"_id": 0})
    discount_amount = quotation.get('discount_amount', 0.0)
    final_total = total - discount_amount
    
    await db.quotations.update_one(
        {"id": quotation_id},
        {"$set": {
            "total": total,
            "final_total": final_total,
            "updated_at": datetime.now(timezone.utc).isoformat()
        }}
    )

# Inventory Reports
@api_router.get("/reports/inventory")
async def get_inventory_report():
    """Get comprehensive inventory report"""
    report = {
        "tiles": [],
        "misc_items": [],
        "summary": {
            "total_tile_value": 0.0,
            "total_misc_value": 0.0,
            "total_inventory_value": 0.0
        }
    }
    
    # Get tiles with stock
    tiles = await db.tiles.find({"status": "active"}, {"_id": 0}).to_list(None)
    for tile in tiles:
        size = await db.tile_sizes.find_one({"id": tile['size_id']}, {"_id": 0})
        stock_info = await calculate_stock_for_item(tile['id'], "tile")
        
        tile_report = {
            "id": tile['id'],
            "name": tile['name'],
            "size_info": size,
            **stock_info
        }
        report['tiles'].append(tile_report)
        report['summary']['total_tile_value'] += stock_info['stock_value']
    
    # Get misc items with stock
    misc_items = await db.misc_items.find({"status": "active"}, {"_id": 0}).to_list(None)
    for item in misc_items:
        stock_info = await calculate_stock_for_item(item['id'], "misc")
        
        item_report = {
            "id": item['id'],
            "name": item['name'],
            "unit_label": item['unit_label'],
            **stock_info
        }
        report['misc_items'].append(item_report)
        report['summary']['total_misc_value'] += stock_info['stock_value']
    
    report['summary']['total_inventory_value'] = (
        report['summary']['total_tile_value'] + 
        report['summary']['total_misc_value']
    )
    
    return report

@api_router.get("/reports/sales")
async def get_sales_report(days: int = Query(30, description="Number of days to include")):
    """Get sales report (simplified - would need invoice system for complete implementation)"""
    # For now, return quotations as sales indicators
    from_date = datetime.now(timezone.utc) - datetime.timedelta(days=days)
    
    quotations = await db.quotations.find({
        "created_at": {"$gte": from_date.isoformat()}
    }, {"_id": 0}).to_list(None)
    
    total_quotation_value = sum(q.get('final_total', 0) for q in quotations)
    
    return {
        "period_days": days,
        "total_quotations": len(quotations),
        "total_quotation_value": total_quotation_value,
        "average_quotation_value": total_quotation_value / len(quotations) if quotations else 0,
        "quotations": quotations
    }

# Include the router in the main app
app.include_router(api_router)

app.add_middleware(
    CORSMiddleware,
    allow_credentials=True,
    allow_origins=os.environ.get('CORS_ORIGINS', '*').split(','),
    allow_methods=["*"],
    allow_headers=["*"],
)

# Configure logging
logging.basicConfig(
    level=logging.INFO,
    format='%(asctime)s - %(name)s - %(levelname)s - %(message)s'
)
logger = logging.getLogger(__name__)

@app.on_event("shutdown")
async def shutdown_db_client():
    client.close()