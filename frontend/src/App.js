import React, { useState, useEffect } from 'react';
import './App.css';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import axios from 'axios';

const BACKEND_URL = process.env.REACT_APP_BACKEND_URL;
const API = `${BACKEND_URL}/api`;

// API service
const api = {
  // Tiles
  getTiles: () => axios.get(`${API}/tiles`),
  createTile: (tile) => axios.post(`${API}/tiles`, tile),
  toggleTileStatus: (tileId) => axios.put(`${API}/tiles/${tileId}/status`),
  
  // Tile Sizes
  getTileSizes: () => axios.get(`${API}/tile-sizes`),
  createTileSize: (size) => axios.post(`${API}/tile-sizes`, size),
  
  // Misc Items
  getMiscItems: () => axios.get(`${API}/misc-items`),
  createMiscItem: (item) => axios.post(`${API}/misc-items`, item),
  toggleMiscItemStatus: (itemId) => axios.put(`${API}/misc-items/${itemId}/status`),
  
  // Purchase Entries
  getPurchaseEntries: (params) => axios.get(`${API}/purchase-entries`, { params }),
  createPurchaseEntry: (entry) => axios.post(`${API}/purchase-entries`, entry),
  
  // Quotations
  getQuotations: () => axios.get(`${API}/quotations`),
  createQuotation: (quotation) => axios.post(`${API}/quotations`, quotation),
  getQuotationDetails: (id) => axios.get(`${API}/quotations/${id}`),
  addQuotationItem: (quotationId, item) => axios.post(`${API}/quotations/${quotationId}/items`, item),
  deleteQuotationItem: (quotationId, itemId) => axios.delete(`${API}/quotations/${quotationId}/items/${itemId}`),
  
  // Reports
  getInventoryReport: () => axios.get(`${API}/reports/inventory`),
  getSalesReport: (days) => axios.get(`${API}/reports/sales`, { params: { days } })
};

// Components
const Navigation = ({ activeTab, setActiveTab }) => {
  const tabs = [
    { id: 'dashboard', name: 'Dashboard', icon: '📊' },
    { id: 'inventory', name: 'Inventory', icon: '📦' },
    { id: 'purchase', name: 'Purchase Entry', icon: '🛒' },
    { id: 'quotations', name: 'Quotations', icon: '📋' },
    { id: 'reports', name: 'Reports', icon: '📈' }
  ];

  return (
    <nav className="bg-white shadow-sm border-b">
      <div className="container mx-auto px-4">
        <div className="flex items-center justify-between h-16">
          <h1 className="text-xl font-bold text-gray-800">🏗️ Tile Inventory System</h1>
          <div className="flex space-x-4">
            {tabs.map(tab => (
              <button
                key={tab.id}
                onClick={() => setActiveTab(tab.id)}
                className={`px-3 py-2 rounded-md text-sm font-medium ${
                  activeTab === tab.id 
                    ? 'bg-blue-500 text-white' 
                    : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100'
                }`}
              >
                {tab.icon} {tab.name}
              </button>
            ))}
          </div>
        </div>
      </div>
    </nav>
  );
};

const Dashboard = () => {
  const [stats, setStats] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    const fetchStats = async () => {
      try {
        const [inventoryResponse, salesResponse] = await Promise.all([
          api.getInventoryReport(),
          api.getSalesReport(30)
        ]);
        
        setStats({
          inventory: inventoryResponse.data,
          sales: salesResponse.data
        });
      } catch (error) {
        console.error('Error fetching dashboard stats:', error);
      } finally {
        setLoading(false);
      }
    };
    
    fetchStats();
  }, []);

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <h2 className="text-2xl font-bold mb-6">Dashboard</h2>
      
      {stats && (
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <div className="bg-blue-50 p-6 rounded-lg">
            <h3 className="text-lg font-semibold text-blue-800">Total Inventory Value</h3>
            <p className="text-3xl font-bold text-blue-600">
              ₹{stats.inventory.summary.total_inventory_value.toLocaleString()}
            </p>
          </div>
          
          <div className="bg-green-50 p-6 rounded-lg">
            <h3 className="text-lg font-semibold text-green-800">Active Tiles</h3>
            <p className="text-3xl font-bold text-green-600">
              {stats.inventory.tiles.length}
            </p>
          </div>
          
          <div className="bg-purple-50 p-6 rounded-lg">
            <h3 className="text-lg font-semibold text-purple-800">Active Items</h3>
            <p className="text-3xl font-bold text-purple-600">
              {stats.inventory.misc_items.length}
            </p>
          </div>
          
          <div className="bg-orange-50 p-6 rounded-lg">
            <h3 className="text-lg font-semibold text-orange-800">Monthly Quotations</h3>
            <p className="text-3xl font-bold text-orange-600">
              {stats.sales.total_quotations}
            </p>
          </div>
        </div>
      )}
      
      <div className="bg-white p-6 rounded-lg shadow">
        <h3 className="text-lg font-semibold mb-4">Quick Actions</h3>
        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
          <button className="p-4 bg-blue-100 rounded-lg hover:bg-blue-200 transition-colors">
            <div className="text-2xl mb-2">➕</div>
            <div className="font-semibold">Add Purchase Entry</div>
          </button>
          <button className="p-4 bg-green-100 rounded-lg hover:bg-green-200 transition-colors">
            <div className="text-2xl mb-2">📋</div>
            <div className="font-semibold">Create Quotation</div>
          </button>
          <button className="p-4 bg-purple-100 rounded-lg hover:bg-purple-200 transition-colors">
            <div className="text-2xl mb-2">📊</div>
            <div className="font-semibold">View Reports</div>
          </button>
        </div>
      </div>
    </div>
  );
};

const InventoryManagement = () => {
  const [activeSubTab, setActiveSubTab] = useState('tiles');
  const [tiles, setTiles] = useState([]);
  const [miscItems, setMiscItems] = useState([]);
  const [tileSizes, setTileSizes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showAddForm, setShowAddForm] = useState(false);
  const [showActiveOnly, setShowActiveOnly] = useState(true);

  useEffect(() => {
    fetchData();
  }, [showActiveOnly]);

  const fetchData = async () => {
    try {
      const [tilesResponse, miscResponse, sizesResponse] = await Promise.all([
        api.getTiles(),
        api.getMiscItems(),
        api.getTileSizes()
      ]);
      
      setTiles(tilesResponse.data.filter(tile => showActiveOnly ? tile.status === 'active' : true));
      setMiscItems(miscResponse.data.filter(item => showActiveOnly ? item.status === 'active' : true));
      setTileSizes(sizesResponse.data);
    } catch (error) {
      console.error('Error fetching inventory:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleToggleStatus = async (id, type) => {
    try {
      if (type === 'tile') {
        await api.toggleTileStatus(id);
      } else {
        await api.toggleMiscItemStatus(id);
      }
      await fetchData();
    } catch (error) {
      console.error('Error toggling status:', error);
      alert('Failed to update item status');
    }
  };

  const AddTileForm = () => {
    const [formData, setFormData] = useState({
      name: '',
      size_id: '',
      vendor_name: '',
      current_cost: 0
    });

    const handleSubmit = async (e) => {
      e.preventDefault();
      try {
        await api.createTile(formData);
        await fetchData();
        setShowAddForm(false);
        setFormData({ name: '', size_id: '', vendor_name: '', current_cost: 0 });
      } catch (error) {
        console.error('Error creating tile:', error);
        alert('Failed to create tile');
      }
    };

    return (
      <form onSubmit={handleSubmit} className="bg-gray-50 p-4 rounded-lg mb-4">
        <h3 className="font-semibold mb-3">Add New Tile</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <input
            type="text"
            placeholder="Tile Name"
            value={formData.name}
            onChange={(e) => setFormData({...formData, name: e.target.value})}
            className="border rounded px-3 py-2"
            required
          />
          <select
            value={formData.size_id}
            onChange={(e) => setFormData({...formData, size_id: e.target.value})}
            className="border rounded px-3 py-2"
            required
          >
            <option value="">Select Size</option>
            {tileSizes.map(size => (
              <option key={size.id} value={size.id}>{size.name}</option>
            ))}
          </select>
          <input
            type="text"
            placeholder="Vendor Name"
            value={formData.vendor_name}
            onChange={(e) => setFormData({...formData, vendor_name: e.target.value})}
            className="border rounded px-3 py-2"
          />
          <input
            type="number"
            step="0.01"
            placeholder="Current Cost"
            value={formData.current_cost}
            onChange={(e) => setFormData({...formData, current_cost: parseFloat(e.target.value) || 0})}
            className="border rounded px-3 py-2"
          />
        </div>
        <div className="mt-4 space-x-2">
          <button type="submit" className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
            Create Tile
          </button>
          <button type="button" onClick={() => setShowAddForm(false)} className="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">
            Cancel
          </button>
        </div>
      </form>
    );
  };

  const AddMiscItemForm = () => {
    const [formData, setFormData] = useState({
      name: '',
      unit_label: 'unit',
      current_cost: 0,
      description: ''
    });

    const handleSubmit = async (e) => {
      e.preventDefault();
      try {
        await api.createMiscItem(formData);
        await fetchData();
        setShowAddForm(false);
        setFormData({ name: '', unit_label: 'unit', current_cost: 0, description: '' });
      } catch (error) {
        console.error('Error creating misc item:', error);
        alert('Failed to create item');
      }
    };

    return (
      <form onSubmit={handleSubmit} className="bg-gray-50 p-4 rounded-lg mb-4">
        <h3 className="font-semibold mb-3">Add New Item</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <input
            type="text"
            placeholder="Item Name"
            value={formData.name}
            onChange={(e) => setFormData({...formData, name: e.target.value})}
            className="border rounded px-3 py-2"
            required
          />
          <input
            type="text"
            placeholder="Unit (e.g., kg, meter)"
            value={formData.unit_label}
            onChange={(e) => setFormData({...formData, unit_label: e.target.value})}
            className="border rounded px-3 py-2"
          />
          <input
            type="number"
            step="0.01"
            placeholder="Current Cost"
            value={formData.current_cost}
            onChange={(e) => setFormData({...formData, current_cost: parseFloat(e.target.value) || 0})}
            className="border rounded px-3 py-2"
          />
          <input
            type="text"
            placeholder="Description"
            value={formData.description}
            onChange={(e) => setFormData({...formData, description: e.target.value})}
            className="border rounded px-3 py-2"
          />
        </div>
        <div className="mt-4 space-x-2">
          <button type="submit" className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
            Create Item
          </button>
          <button type="button" onClick={() => setShowAddForm(false)} className="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">
            Cancel
          </button>
        </div>
      </form>
    );
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h2 className="text-2xl font-bold">Inventory Management</h2>
        <div className="flex items-center space-x-4">
          <label className="flex items-center">
            <input
              type="checkbox"
              checked={showActiveOnly}
              onChange={(e) => setShowActiveOnly(e.target.checked)}
              className="mr-2"
            />
            Show active only
          </label>
          <button
            onClick={() => setShowAddForm(true)}
            className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"
          >
            ➕ Add {activeSubTab === 'tiles' ? 'Tile' : 'Item'}
          </button>
        </div>
      </div>

      <div className="flex space-x-4 mb-6">
        <button
          onClick={() => setActiveSubTab('tiles')}
          className={`px-4 py-2 rounded ${activeSubTab === 'tiles' ? 'bg-blue-500 text-white' : 'bg-gray-200'}`}
        >
          🏠 Tiles ({tiles.length})
        </button>
        <button
          onClick={() => setActiveSubTab('misc')}
          className={`px-4 py-2 rounded ${activeSubTab === 'misc' ? 'bg-blue-500 text-white' : 'bg-gray-200'}`}
        >
          🔧 Other Items ({miscItems.length})
        </button>
      </div>

      {showAddForm && (activeSubTab === 'tiles' ? <AddTileForm /> : <AddMiscItemForm />)}

      {activeSubTab === 'tiles' ? (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tile</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {tiles.map(tile => (
                <tr key={tile.id} className={tile.status === 'inactive' ? 'bg-gray-50 opacity-75' : ''}>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm font-medium text-gray-900">{tile.name}</div>
                    {tile.vendor_name && <div className="text-sm text-gray-500">Vendor: {tile.vendor_name}</div>}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    {tile.size_info && (
                      <div className="text-sm text-gray-900">
                        {tile.size_info.name}
                        <div className="text-xs text-gray-500">
                          {tile.size_info.sqft_per_box} sq.ft/box
                        </div>
                      </div>
                    )}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm text-gray-900">{tile.current_stock?.toFixed(1) || 0} boxes</div>
                    <div className="text-xs text-gray-500">
                      {((tile.current_stock || 0) * (tile.size_info?.sqft_per_box || 0)).toFixed(1)} sq.ft
                    </div>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ₹{tile.stock_value?.toFixed(2) || '0.00'}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                      tile.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                    }`}>
                      {tile.status}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button
                      onClick={() => handleToggleStatus(tile.id, 'tile')}
                      className={`${tile.status === 'active' ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'}`}
                    >
                      {tile.status === 'active' ? '👁️‍🗨️ Hide' : '👁️ Show'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <div className="bg-white rounded-lg shadow overflow-hidden">
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Item</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Unit</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Stock</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Value</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {miscItems.map(item => (
                <tr key={item.id} className={item.status === 'inactive' ? 'bg-gray-50 opacity-75' : ''}>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <div className="text-sm font-medium text-gray-900">{item.name}</div>
                    {item.description && <div className="text-sm text-gray-500">{item.description}</div>}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {item.unit_label}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    {item.current_stock?.toFixed(1) || 0} {item.unit_label}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                    ₹{item.stock_value?.toFixed(2) || '0.00'}
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap">
                    <span className={`px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                      item.status === 'active' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                    }`}>
                      {item.status}
                    </span>
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button
                      onClick={() => handleToggleStatus(item.id, 'misc')}
                      className={`${item.status === 'active' ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'}`}
                    >
                      {item.status === 'active' ? '👁️‍🗨️ Hide' : '👁️ Show'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

const PurchaseEntry = () => {
  const [tiles, setTiles] = useState([]);
  const [miscItems, setMiscItems] = useState([]);
  const [recentEntries, setRecentEntries] = useState([]);
  const [loading, setLoading] = useState(true);
  const [formData, setFormData] = useState({
    item_id: '',
    item_type: 'tile',
    purchase_date: new Date().toISOString().split('T')[0],
    total_quantity: '',
    damage_percentage: 0,
    cost_per_unit: '',
    transport_cost: 0,
    supplier_name: '',
    invoice_number: '',
    notes: ''
  });

  useEffect(() => {
    fetchData();
  }, []);

  const fetchData = async () => {
    try {
      const [tilesResponse, miscResponse, entriesResponse] = await Promise.all([
        api.getTiles(),
        api.getMiscItems(),
        api.getPurchaseEntries({ limit: 20 })
      ]);
      
      setTiles(tilesResponse.data.filter(tile => tile.status === 'active'));
      setMiscItems(miscResponse.data.filter(item => item.status === 'active'));
      setRecentEntries(entriesResponse.data);
    } catch (error) {
      console.error('Error fetching data:', error);
    } finally {
      setLoading(false);
    }
  };

  const handleSubmit = async (e) => {
    e.preventDefault();
    try {
      const entryData = {
        ...formData,
        purchase_date: new Date(formData.purchase_date).toISOString(),
        total_quantity: parseFloat(formData.total_quantity),
        damage_percentage: parseFloat(formData.damage_percentage),
        cost_per_unit: parseFloat(formData.cost_per_unit),
        transport_cost: parseFloat(formData.transport_cost)
      };
      
      await api.createPurchaseEntry(entryData);
      await fetchData();
      
      // Reset form
      setFormData({
        item_id: '',
        item_type: 'tile',
        purchase_date: new Date().toISOString().split('T')[0],
        total_quantity: '',
        damage_percentage: 0,
        cost_per_unit: '',
        transport_cost: 0,
        supplier_name: '',
        invoice_number: '',
        notes: ''
      });
      
      alert('Purchase entry added successfully!');
    } catch (error) {
      console.error('Error creating purchase entry:', error);
      alert(error.response?.data?.detail || 'Failed to create purchase entry');
    }
  };

  const getSelectedItem = () => {
    if (!formData.item_id) return null;
    
    const items = formData.item_type === 'tile' ? tiles : miscItems;
    return items.find(item => item.id === formData.item_id);
  };

  const calculateTotals = () => {
    const totalQty = parseFloat(formData.total_quantity) || 0;
    const damagePercent = parseFloat(formData.damage_percentage) || 0;
    const costPerUnit = parseFloat(formData.cost_per_unit) || 0;
    const transportCost = parseFloat(formData.transport_cost) || 0;
    
    const usableQty = totalQty * (1 - damagePercent / 100);
    const materialCost = totalQty * costPerUnit;
    const totalCost = materialCost + transportCost;
    
    return { usableQty, materialCost, totalCost };
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  const { usableQty, materialCost, totalCost } = calculateTotals();
  const selectedItem = getSelectedItem();

  return (
    <div className="p-6">
      <h2 className="text-2xl font-bold mb-6">Purchase Entry</h2>
      
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
        {/* Purchase Form */}
        <div className="bg-white p-6 rounded-lg shadow">
          <h3 className="text-lg font-semibold mb-4">Add Purchase Entry</h3>
          
          <form onSubmit={handleSubmit} className="space-y-4">
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Item Type</label>
              <select
                value={formData.item_type}
                onChange={(e) => setFormData({ ...formData, item_type: e.target.value, item_id: '' })}
                className="w-full border rounded px-3 py-2"
              >
                <option value="tile">Tile</option>
                <option value="misc">Other Item</option>
              </select>
            </div>
            
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Select Item</label>
              <select
                value={formData.item_id}
                onChange={(e) => setFormData({ ...formData, item_id: e.target.value })}
                className="w-full border rounded px-3 py-2"
                required
              >
                <option value="">Choose {formData.item_type}...</option>
                {(formData.item_type === 'tile' ? tiles : miscItems).map(item => (
                  <option key={item.id} value={item.id}>
                    {item.name} {item.size_info && `(${item.size_info.name})`}
                    {formData.item_type === 'misc' && ` - ${item.unit_label}`}
                  </option>
                ))}
              </select>
            </div>
            
            {selectedItem && (
              <div className="bg-blue-50 p-3 rounded">
                <p className="text-sm"><strong>Current Stock:</strong> {selectedItem.current_stock?.toFixed(1) || 0}</p>
                <p className="text-sm"><strong>Current Value:</strong> ₹{selectedItem.stock_value?.toFixed(2) || '0.00'}</p>
              </div>
            )}
            
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Purchase Date</label>
                <input
                  type="date"
                  value={formData.purchase_date}
                  onChange={(e) => setFormData({ ...formData, purchase_date: e.target.value })}
                  className="w-full border rounded px-3 py-2"
                  required
                />
              </div>
              
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Total Quantity</label>
                <input
                  type="number"
                  step="0.01"
                  value={formData.total_quantity}
                  onChange={(e) => setFormData({ ...formData, total_quantity: e.target.value })}
                  className="w-full border rounded px-3 py-2"
                  placeholder="0.00"
                  required
                />
              </div>
            </div>
            
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Damage %</label>
                <input
                  type="number"
                  step="0.1"
                  min="0"
                  max="100"
                  value={formData.damage_percentage}
                  onChange={(e) => setFormData({ ...formData, damage_percentage: e.target.value })}
                  className="w-full border rounded px-3 py-2"
                  placeholder="0.0"
                />
              </div>
              
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Cost per Unit (₹)</label>
                <input
                  type="number"
                  step="0.01"
                  value={formData.cost_per_unit}
                  onChange={(e) => setFormData({ ...formData, cost_per_unit: e.target.value })}
                  className="w-full border rounded px-3 py-2"
                  placeholder="0.00"
                  required
                />
              </div>
            </div>
            
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Transport Cost (₹)</label>
              <input
                type="number"
                step="0.01"
                value={formData.transport_cost}
                onChange={(e) => setFormData({ ...formData, transport_cost: e.target.value })}
                className="w-full border rounded px-3 py-2"
                placeholder="0.00"
              />
            </div>
            
            <div className="grid grid-cols-2 gap-4">
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Supplier Name</label>
                <input
                  type="text"
                  value={formData.supplier_name}
                  onChange={(e) => setFormData({ ...formData, supplier_name: e.target.value })}
                  className="w-full border rounded px-3 py-2"
                  placeholder="Supplier name"
                />
              </div>
              
              <div>
                <label className="block text-sm font-medium text-gray-700 mb-1">Invoice Number</label>
                <input
                  type="text"
                  value={formData.invoice_number}
                  onChange={(e) => setFormData({ ...formData, invoice_number: e.target.value })}
                  className="w-full border rounded px-3 py-2"
                  placeholder="Invoice #"
                />
              </div>
            </div>
            
            <div>
              <label className="block text-sm font-medium text-gray-700 mb-1">Notes</label>
              <textarea
                value={formData.notes}
                onChange={(e) => setFormData({ ...formData, notes: e.target.value })}
                className="w-full border rounded px-3 py-2"
                rows="2"
                placeholder="Additional notes"
              />
            </div>
            
            {(formData.total_quantity && formData.cost_per_unit) && (
              <div className="bg-green-50 p-4 rounded">
                <h4 className="font-semibold mb-2">Purchase Summary</h4>
                <div className="grid grid-cols-3 gap-4 text-sm">
                  <div>
                    <p className="text-gray-600">Usable Quantity</p>
                    <p className="font-semibold">{usableQty.toFixed(2)}</p>
                  </div>
                  <div>
                    <p className="text-gray-600">Material Cost</p>
                    <p className="font-semibold">₹{materialCost.toFixed(2)}</p>
                  </div>
                  <div>
                    <p className="text-gray-600">Total Cost</p>
                    <p className="font-semibold text-green-600">₹{totalCost.toFixed(2)}</p>
                  </div>
                </div>
              </div>
            )}
            
            <button
              type="submit"
              className="w-full bg-blue-500 text-white py-2 px-4 rounded hover:bg-blue-600"
            >
              Add Purchase Entry
            </button>
          </form>
        </div>
        
        {/* Recent Entries */}
        <div className="bg-white p-6 rounded-lg shadow">
          <h3 className="text-lg font-semibold mb-4">Recent Purchase Entries</h3>
          
          <div className="space-y-3 max-h-96 overflow-y-auto">
            {recentEntries.map(entry => (
              <div key={entry.id} className="border rounded p-3">
                <div className="flex justify-between items-start mb-2">
                  <div>
                    <h4 className="font-medium">{entry.item_info?.name || 'Unknown Item'}</h4>
                    <p className="text-sm text-gray-500">
                      {new Date(entry.purchase_date).toLocaleDateString()}
                    </p>
                  </div>
                  <span className={`px-2 py-1 rounded text-xs ${
                    entry.item_info?.status === 'active' 
                      ? 'bg-green-100 text-green-800' 
                      : 'bg-red-100 text-red-800'
                  }`}>
                    {entry.item_info?.status || 'unknown'}
                  </span>
                </div>
                
                <div className="grid grid-cols-2 gap-2 text-sm">
                  <div>
                    <span className="text-gray-600">Usable: </span>
                    <span className="font-medium">{entry.usable_quantity?.toFixed(2)}</span>
                  </div>
                  <div>
                    <span className="text-gray-600">Cost: </span>
                    <span className="font-medium">₹{entry.total_cost?.toFixed(2)}</span>
                  </div>
                </div>
                
                {entry.supplier_name && (
                  <p className="text-xs text-gray-500 mt-1">
                    Supplier: {entry.supplier_name}
                  </p>
                )}
              </div>
            ))}
          </div>
        </div>
      </div>
    </div>
  );
};

const QuotationManagement = () => {
  const [quotations, setQuotations] = useState([]);
  const [selectedQuotation, setSelectedQuotation] = useState(null);
  const [showCreateForm, setShowCreateForm] = useState(false);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchQuotations();
  }, []);

  const fetchQuotations = async () => {
    try {
      const response = await api.getQuotations();
      setQuotations(response.data);
    } catch (error) {
      console.error('Error fetching quotations:', error);
    } finally {
      setLoading(false);
    }
  };

  const CreateQuotationForm = () => {
    const [formData, setFormData] = useState({
      customer_name: '',
      firm_name: '',
      phone: '',
      customer_gst: '',
      notes: ''
    });

    const handleSubmit = async (e) => {
      e.preventDefault();
      try {
        const response = await api.createQuotation(formData);
        await fetchQuotations();
        setShowCreateForm(false);
        setSelectedQuotation(response.data.id);
      } catch (error) {
        console.error('Error creating quotation:', error);
        alert('Failed to create quotation');
      }
    };

    return (
      <form onSubmit={handleSubmit} className="bg-gray-50 p-4 rounded-lg mb-4">
        <h3 className="font-semibold mb-3">Create New Quotation</h3>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <input
            type="text"
            placeholder="Customer Name *"
            value={formData.customer_name}
            onChange={(e) => setFormData({...formData, customer_name: e.target.value})}
            className="border rounded px-3 py-2"
            required
          />
          <input
            type="text"
            placeholder="Firm Name"
            value={formData.firm_name}
            onChange={(e) => setFormData({...formData, firm_name: e.target.value})}
            className="border rounded px-3 py-2"
          />
          <input
            type="tel"
            placeholder="Mobile Number *"
            value={formData.phone}
            onChange={(e) => setFormData({...formData, phone: e.target.value})}
            className="border rounded px-3 py-2"
            pattern="[0-9]{10}"
            required
          />
          <input
            type="text"
            placeholder="GST Number"
            value={formData.customer_gst}
            onChange={(e) => setFormData({...formData, customer_gst: e.target.value})}
            className="border rounded px-3 py-2"
          />
        </div>
        <div className="mt-4">
          <textarea
            placeholder="Notes"
            value={formData.notes}
            onChange={(e) => setFormData({...formData, notes: e.target.value})}
            className="w-full border rounded px-3 py-2"
            rows="2"
          />
        </div>
        <div className="mt-4 space-x-2">
          <button type="submit" className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
            Create Quotation
          </button>
          <button type="button" onClick={() => setShowCreateForm(false)} className="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">
            Cancel
          </button>
        </div>
      </form>
    );
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <div className="flex justify-between items-center mb-6">
        <h2 className="text-2xl font-bold">Quotation Management</h2>
        <button
          onClick={() => setShowCreateForm(true)}
          className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600"
        >
          ➕ Create Quotation
        </button>
      </div>

      {showCreateForm && <CreateQuotationForm />}

      <div className="bg-white rounded-lg shadow overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quote #</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Customer</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody className="bg-white divide-y divide-gray-200">
            {quotations.map(quotation => (
              <tr key={quotation.id}>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  {quotation.quote_no}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <div className="text-sm text-gray-900">{quotation.customer_name}</div>
                  {quotation.firm_name && <div className="text-sm text-gray-500">{quotation.firm_name}</div>}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {new Date(quotation.quote_date).toLocaleDateString()}
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  ₹{quotation.final_total.toFixed(2)}
                </td>
                <td className="px-6 py-4 whitespace-nowrap">
                  <span className="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-800">
                    {quotation.status}
                  </span>
                </td>
                <td className="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <button
                    onClick={() => setSelectedQuotation(quotation.id)}
                    className="text-blue-600 hover:text-blue-900 mr-3"
                  >
                    View Details
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      
      {selectedQuotation && (
        <QuotationDetails 
          quotationId={selectedQuotation} 
          onClose={() => setSelectedQuotation(null)}
        />
      )}
    </div>
  );
};

const QuotationDetails = ({ quotationId, onClose }) => {
  const [quotation, setQuotation] = useState(null);
  const [tiles, setTiles] = useState([]);
  const [miscItems, setMiscItems] = useState([]);
  const [loading, setLoading] = useState(true);
  const [showAddItemForm, setShowAddItemForm] = useState(false);

  useEffect(() => {
    fetchQuotationDetails();
    fetchItems();
  }, [quotationId]);

  const fetchQuotationDetails = async () => {
    try {
      const response = await api.getQuotationDetails(quotationId);
      setQuotation(response.data);
    } catch (error) {
      console.error('Error fetching quotation details:', error);
    } finally {
      setLoading(false);
    }
  };

  const fetchItems = async () => {
    try {
      const [tilesResponse, miscResponse] = await Promise.all([
        api.getTiles(),
        api.getMiscItems()
      ]);
      setTiles(tilesResponse.data.filter(tile => tile.status === 'active'));
      setMiscItems(miscResponse.data.filter(item => item.status === 'active'));
    } catch (error) {
      console.error('Error fetching items:', error);
    }
  };

  const AddItemForm = () => {
    const [formData, setFormData] = useState({
      item_id: '',
      item_type: 'tile',
      purpose: '',
      quantity: '',
      rate_per_unit: ''
    });

    const handleSubmit = async (e) => {
      e.preventDefault();
      try {
        await api.addQuotationItem(quotationId, {
          ...formData,
          quantity: parseFloat(formData.quantity),
          rate_per_unit: parseFloat(formData.rate_per_unit)
        });
        await fetchQuotationDetails();
        setShowAddItemForm(false);
        setFormData({ item_id: '', item_type: 'tile', purpose: '', quantity: '', rate_per_unit: '' });
      } catch (error) {
        console.error('Error adding item:', error);
        alert(error.response?.data?.detail || 'Failed to add item');
      }
    };

    return (
      <form onSubmit={handleSubmit} className="bg-gray-50 p-4 rounded-lg mb-4">
        <h4 className="font-semibold mb-3">Add Item to Quotation</h4>
        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <select
            value={formData.item_type}
            onChange={(e) => setFormData({ ...formData, item_type: e.target.value, item_id: '' })}
            className="border rounded px-3 py-2"
          >
            <option value="tile">Tile</option>
            <option value="misc">Other Item</option>
          </select>
          
          <select
            value={formData.item_id}
            onChange={(e) => setFormData({ ...formData, item_id: e.target.value })}
            className="border rounded px-3 py-2"
            required
          >
            <option value="">Select Item</option>
            {(formData.item_type === 'tile' ? tiles : miscItems).map(item => (
              <option key={item.id} value={item.id}>
                {item.name} (Stock: {item.current_stock?.toFixed(1) || 0})
              </option>
            ))}
          </select>
          
          <input
            type="text"
            placeholder="Purpose"
            value={formData.purpose}
            onChange={(e) => setFormData({ ...formData, purpose: e.target.value })}
            className="border rounded px-3 py-2"
          />
          
          <input
            type="number"
            step="0.01"
            placeholder="Quantity"
            value={formData.quantity}
            onChange={(e) => setFormData({ ...formData, quantity: e.target.value })}
            className="border rounded px-3 py-2"
            required
          />
          
          <input
            type="number"
            step="0.01"
            placeholder="Rate per Unit"
            value={formData.rate_per_unit}
            onChange={(e) => setFormData({ ...formData, rate_per_unit: e.target.value })}
            className="border rounded px-3 py-2"
            required
          />
        </div>
        <div className="mt-4 space-x-2">
          <button type="submit" className="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
            Add Item
          </button>
          <button type="button" onClick={() => setShowAddItemForm(false)} className="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">
            Cancel
          </button>
        </div>
      </form>
    );
  };

  if (loading) {
    return (
      <div className="fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-white"></div>
      </div>
    );
  }

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex justify-center items-center p-4">
      <div className="bg-white rounded-lg max-w-4xl w-full max-h-full overflow-y-auto">
        <div className="p-6">
          <div className="flex justify-between items-center mb-4">
            <h3 className="text-xl font-bold">Quotation Details - {quotation.quote_no}</h3>
            <button onClick={onClose} className="text-gray-500 hover:text-gray-700">
              ✕
            </button>
          </div>
          
          <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
              <h4 className="font-semibold mb-2">Customer Information</h4>
              <div className="space-y-1 text-sm">
                <p><strong>Name:</strong> {quotation.customer_name}</p>
                {quotation.firm_name && <p><strong>Firm:</strong> {quotation.firm_name}</p>}
                <p><strong>Phone:</strong> {quotation.phone}</p>
                {quotation.customer_gst && <p><strong>GST:</strong> {quotation.customer_gst}</p>}
                <p><strong>Date:</strong> {new Date(quotation.quote_date).toLocaleDateString()}</p>
              </div>
            </div>
            
            <div>
              <h4 className="font-semibold mb-2">Quote Summary</h4>
              <div className="space-y-1 text-sm">
                <p><strong>Subtotal:</strong> ₹{quotation.total.toFixed(2)}</p>
                {quotation.discount_amount > 0 && (
                  <p><strong>Discount:</strong> -₹{quotation.discount_amount.toFixed(2)}</p>
                )}
                <p className="text-lg font-bold"><strong>Total:</strong> ₹{quotation.final_total.toFixed(2)}</p>
              </div>
            </div>
          </div>
          
          <div className="mb-4">
            <div className="flex justify-between items-center mb-2">
              <h4 className="font-semibold">Quotation Items ({quotation.items.length})</h4>
              <button
                onClick={() => setShowAddItemForm(true)}
                className="bg-blue-500 text-white px-3 py-1 rounded hover:bg-blue-600 text-sm"
              >
                ➕ Add Item
              </button>
            </div>
            
            {showAddItemForm && <AddItemForm />}
            
            <div className="space-y-3">
              {quotation.items.map(item => (
                <div key={item.id} className="border rounded p-3">
                  <div className="flex justify-between items-start">
                    <div className="flex-1">
                      <h5 className="font-medium">{item.item_name}</h5>
                      {item.purpose && <p className="text-sm text-gray-600">{item.purpose}</p>}
                      <div className="text-sm text-gray-500 mt-1">
                        {item.quantity} × ₹{item.rate_per_unit} = ₹{item.line_total.toFixed(2)}
                      </div>
                    </div>
                    <button
                      onClick={async () => {
                        if (confirm('Delete this item?')) {
                          try {
                            await api.deleteQuotationItem(quotationId, item.id);
                            await fetchQuotationDetails();
                          } catch (error) {
                            console.error('Error deleting item:', error);
                            alert('Failed to delete item');
                          }
                        }
                      }}
                      className="text-red-600 hover:text-red-800 text-sm"
                    >
                      🗑️
                    </button>
                  </div>
                </div>
              ))}
            </div>
          </div>
          
          <div className="flex justify-end space-x-2">
            <button onClick={onClose} className="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">
              Close
            </button>
            <button className="bg-green-500 text-white px-4 py-2 rounded hover:bg-green-600">
              📄 Print Quotation
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

const Reports = () => {
  const [activeReport, setActiveReport] = useState('inventory');
  const [inventoryReport, setInventoryReport] = useState(null);
  const [salesReport, setSalesReport] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    fetchReports();
  }, []);

  const fetchReports = async () => {
    try {
      const [inventoryResponse, salesResponse] = await Promise.all([
        api.getInventoryReport(),
        api.getSalesReport(30)
      ]);
      
      setInventoryReport(inventoryResponse.data);
      setSalesReport(salesResponse.data);
    } catch (error) {
      console.error('Error fetching reports:', error);
    } finally {
      setLoading(false);
    }
  };

  if (loading) {
    return (
      <div className="flex justify-center items-center h-64">
        <div className="animate-spin rounded-full h-12 w-12 border-b-2 border-blue-500"></div>
      </div>
    );
  }

  return (
    <div className="p-6">
      <h2 className="text-2xl font-bold mb-6">Reports</h2>
      
      <div className="flex space-x-4 mb-6">
        <button
          onClick={() => setActiveReport('inventory')}
          className={`px-4 py-2 rounded ${activeReport === 'inventory' ? 'bg-blue-500 text-white' : 'bg-gray-200'}`}
        >
          📦 Inventory Report
        </button>
        <button
          onClick={() => setActiveReport('sales')}
          className={`px-4 py-2 rounded ${activeReport === 'sales' ? 'bg-blue-500 text-white' : 'bg-gray-200'}`}
        >
          💰 Sales Report
        </button>
      </div>
      
      {activeReport === 'inventory' && inventoryReport && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div className="bg-blue-50 p-4 rounded-lg">
              <h3 className="font-semibold text-blue-800">Total Inventory Value</h3>
              <p className="text-2xl font-bold text-blue-600">
                ₹{inventoryReport.summary.total_inventory_value.toLocaleString()}
              </p>
            </div>
            <div className="bg-green-50 p-4 rounded-lg">
              <h3 className="font-semibold text-green-800">Tiles Value</h3>
              <p className="text-2xl font-bold text-green-600">
                ₹{inventoryReport.summary.total_tile_value.toLocaleString()}
              </p>
            </div>
            <div className="bg-purple-50 p-4 rounded-lg">
              <h3 className="font-semibold text-purple-800">Items Value</h3>
              <p className="text-2xl font-bold text-purple-600">
                ₹{inventoryReport.summary.total_misc_value.toLocaleString()}
              </p>
            </div>
          </div>
          
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div className="bg-white p-4 rounded-lg shadow">
              <h3 className="font-semibold mb-4">Tiles Inventory</h3>
              <div className="space-y-2 max-h-64 overflow-y-auto">
                {inventoryReport.tiles.map(tile => (
                  <div key={tile.id} className="flex justify-between items-center p-2 bg-gray-50 rounded">
                    <div>
                      <p className="font-medium">{tile.name}</p>
                      <p className="text-sm text-gray-600">
                        {tile.size_info?.name} - {tile.current_stock?.toFixed(1)} boxes
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="font-medium">₹{tile.stock_value?.toFixed(2)}</p>
                      <p className="text-sm text-gray-600">₹{tile.average_cost?.toFixed(2)}/box</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            
            <div className="bg-white p-4 rounded-lg shadow">
              <h3 className="font-semibold mb-4">Other Items Inventory</h3>
              <div className="space-y-2 max-h-64 overflow-y-auto">
                {inventoryReport.misc_items.map(item => (
                  <div key={item.id} className="flex justify-between items-center p-2 bg-gray-50 rounded">
                    <div>
                      <p className="font-medium">{item.name}</p>
                      <p className="text-sm text-gray-600">
                        {item.current_stock?.toFixed(1)} {item.unit_label}
                      </p>
                    </div>
                    <div className="text-right">
                      <p className="font-medium">₹{item.stock_value?.toFixed(2)}</p>
                      <p className="text-sm text-gray-600">₹{item.average_cost?.toFixed(2)}/{item.unit_label}</p>
                    </div>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}
      
      {activeReport === 'sales' && salesReport && (
        <div className="space-y-6">
          <div className="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div className="bg-orange-50 p-4 rounded-lg">
              <h3 className="font-semibold text-orange-800">Total Quotations</h3>
              <p className="text-2xl font-bold text-orange-600">
                {salesReport.total_quotations}
              </p>
            </div>
            <div className="bg-green-50 p-4 rounded-lg">
              <h3 className="font-semibold text-green-800">Total Value</h3>
              <p className="text-2xl font-bold text-green-600">
                ₹{salesReport.total_quotation_value.toLocaleString()}
              </p>
            </div>
            <div className="bg-blue-50 p-4 rounded-lg">
              <h3 className="font-semibold text-blue-800">Average Value</h3>
              <p className="text-2xl font-bold text-blue-600">
                ₹{salesReport.average_quotation_value.toLocaleString()}
              </p>
            </div>
          </div>
          
          <div className="bg-white p-4 rounded-lg shadow">
            <h3 className="font-semibold mb-4">Recent Quotations (Last {salesReport.period_days} days)</h3>
            <div className="space-y-2 max-h-64 overflow-y-auto">
              {salesReport.quotations.map(quotation => (
                <div key={quotation.id} className="flex justify-between items-center p-2 bg-gray-50 rounded">
                  <div>
                    <p className="font-medium">{quotation.quote_no}</p>
                    <p className="text-sm text-gray-600">{quotation.customer_name}</p>
                  </div>
                  <div className="text-right">
                    <p className="font-medium">₹{quotation.final_total?.toFixed(2)}</p>
                    <p className="text-sm text-gray-600">
                      {new Date(quotation.quote_date).toLocaleDateString()}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
};

function App() {
  const [activeTab, setActiveTab] = useState('dashboard');

  const renderContent = () => {
    switch (activeTab) {
      case 'dashboard':
        return <Dashboard />;
      case 'inventory':
        return <InventoryManagement />;
      case 'purchase':
        return <PurchaseEntry />;
      case 'quotations':
        return <QuotationManagement />;
      case 'reports':
        return <Reports />;
      default:
        return <Dashboard />;
    }
  };

  return (
    <div className="min-h-screen bg-gray-100">
      <BrowserRouter>
        <Navigation activeTab={activeTab} setActiveTab={setActiveTab} />
        <main>
          {renderContent()}
        </main>
      </BrowserRouter>
    </div>
  );
}

export default App;