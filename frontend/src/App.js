import React, { useState, useEffect } from "react";
import "@/App.css";
import { BrowserRouter, Routes, Route, Navigate } from "react-router-dom";
import axios from "axios";

const BACKEND_URL = process.env.REACT_APP_BACKEND_URL;
const API = `${BACKEND_URL}/api`;

// Login Component
const LoginPage = ({ onLogin }) => {
  const [credentials, setCredentials] = useState({ username: '', password: '' });
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  const handleSubmit = async (e) => {
    e.preventDefault();
    setLoading(true);
    setError('');
    
    try {
      // For now, we'll do client-side validation
      // In production, this should authenticate with the PHP backend
      if (credentials.username === 'admin' && credentials.password === 'admin123') {
        localStorage.setItem('user', JSON.stringify({ username: 'admin', role: 'admin' }));
        onLogin({ username: 'admin', role: 'admin' });
      } else if (credentials.username === 'manager1' && credentials.password === 'manager123') {
        localStorage.setItem('user', JSON.stringify({ username: 'manager1', role: 'manager' }));
        onLogin({ username: 'manager1', role: 'manager' });
      } else if (credentials.username === 'sales1' && credentials.password === 'sales123') {
        localStorage.setItem('user', JSON.stringify({ username: 'sales1', role: 'sales' }));
        onLogin({ username: 'sales1', role: 'sales' });
      } else {
        setError('Invalid username or password');
      }
    } catch (err) {
      setError('Login failed. Please try again.');
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="min-h-screen bg-gradient-to-br from-purple-900 via-blue-900 to-indigo-900 flex items-center justify-center p-4">
      <div className="max-w-md w-full">
        <div className="bg-white rounded-2xl shadow-2xl overflow-hidden">
          {/* Header */}
          <div className="bg-gradient-to-r from-purple-600 to-blue-600 text-white p-8 text-center">
            <div className="text-5xl mb-4">🧱</div>
            <h2 className="text-2xl font-bold mb-2">Tile Suite Business</h2>
            <p className="text-purple-100">Complete Business Management</p>
            
            {/* Demo Credentials */}
            <div className="mt-6 bg-white/10 rounded-xl p-4 text-sm">
              <h3 className="font-semibold mb-2">Demo Credentials:</h3>
              <div className="grid grid-cols-2 gap-4 text-xs">
                <div>
                  <strong>Admin:</strong><br />
                  admin / admin123
                </div>
                <div>
                  <strong>Manager:</strong><br />
                  manager1 / manager123
                </div>
              </div>
              <div className="mt-2">
                <strong>Sales:</strong> sales1 / sales123
              </div>
            </div>
          </div>
          
          {/* Form */}
          <div className="p-8">
            {error && (
              <div className="mb-6 bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg">
                ⚠️ {error}
              </div>
            )}
            
            <form onSubmit={handleSubmit} className="space-y-6">
              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2">Username</label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">👤</span>
                  <input
                    type="text"
                    className="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                    placeholder="Enter username"
                    value={credentials.username}
                    onChange={(e) => setCredentials({...credentials, username: e.target.value})}
                    required
                  />
                </div>
              </div>
              
              <div>
                <label className="block text-sm font-semibold text-gray-700 mb-2">Password</label>
                <div className="relative">
                  <span className="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400">🔒</span>
                  <input
                    type="password"
                    className="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-lg focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                    placeholder="Enter password"
                    value={credentials.password}
                    onChange={(e) => setCredentials({...credentials, password: e.target.value})}
                    required
                  />
                </div>
              </div>
              
              <button
                type="submit"
                disabled={loading}
                className="w-full bg-gradient-to-r from-purple-600 to-blue-600 text-white py-3 rounded-lg font-semibold hover:from-purple-700 hover:to-blue-700 transition duration-200 disabled:opacity-50"
              >
                {loading ? '⏳ Signing in...' : '🚀 Sign In to Dashboard'}
              </button>
            </form>
            
            <div className="mt-6 text-center text-sm text-gray-500">
              🔒 Secure authentication system
            </div>
          </div>
          
          {/* Footer */}
          <div className="bg-gray-50 px-8 py-4">
            <div className="grid grid-cols-3 gap-4 text-center text-sm">
              <div className="text-blue-600">
                📈<br />Sales
              </div>
              <div className="text-green-600">
                📦<br />Inventory  
              </div>
              <div className="text-purple-600">
                📊<br />Reports
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// Dashboard Component
const Dashboard = ({ user, onLogout }) => {
  const [stats, setStats] = useState({
    tiles: 0,
    quotes: 0,
    invoices: 0,
    revenue: 0
  });

  useEffect(() => {
    // Simulate fetching stats
    setStats({
      tiles: 1250,
      quotes: 89,
      invoices: 156,
      revenue: 125000
    });
  }, []);

  const quickActions = [
    { name: 'Tiles & Sizes', icon: '🧱', color: 'blue', href: '#' },
    { name: 'New Quotation', icon: '📋', color: 'green', href: '#' },
    { name: 'New Invoice', icon: '💳', color: 'yellow', href: '#' },
    { name: 'Inventory', icon: '📦', color: 'purple', href: '#' },
    { name: 'Reports', icon: '📊', color: 'indigo', href: '#' },
    { name: 'Add Expense', icon: '💰', color: 'gray', href: '#' }
  ];

  const getColorClasses = (color) => {
    const colors = {
      blue: 'bg-blue-500 hover:bg-blue-600',
      green: 'bg-green-500 hover:bg-green-600', 
      yellow: 'bg-yellow-500 hover:bg-yellow-600',
      purple: 'bg-purple-500 hover:bg-purple-600',
      indigo: 'bg-indigo-500 hover:bg-indigo-600',
      gray: 'bg-gray-500 hover:bg-gray-600'
    };
    return colors[color] || colors.gray;
  };

  return (
    <div className="min-h-screen bg-gray-50">
      {/* Header */}
      <div className="bg-gradient-to-r from-purple-600 to-blue-600 text-white shadow-lg">
        <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div className="flex justify-between items-center py-6">
            <div className="flex items-center">
              <div className="text-3xl mr-4">🧱</div>
              <div>
                <h1 className="text-2xl font-bold">Business Dashboard</h1>
                <p className="text-purple-100">Welcome back, {user.username}! Here's your business overview.</p>
              </div>
            </div>
            <div className="flex items-center space-x-4">
              <div className="bg-white/10 rounded-lg px-4 py-2">
                <div className="text-sm font-medium">Today's Date</div>
                <div className="text-lg font-bold">{new Date().toLocaleDateString()}</div>
              </div>
              <button
                onClick={onLogout}
                className="bg-white/10 hover:bg-white/20 px-4 py-2 rounded-lg transition duration-200"
              >
                🚪 Logout
              </button>
            </div>
          </div>
        </div>
      </div>

      <div className="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        {/* Time Period Filter */}
        <div className="flex justify-between items-center mb-8">
          <div className="flex items-center space-x-4">
            <span className="text-gray-700 font-semibold">Time Period:</span>
            <button className="px-4 py-2 bg-purple-100 text-purple-700 rounded-full hover:bg-purple-200 transition">
              📅 Today
            </button>
            <button className="px-4 py-2 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200 transition">
              📊 Last 7 days
            </button>
            <button className="px-4 py-2 bg-gray-100 text-gray-700 rounded-full hover:bg-gray-200 transition">
              📈 Last 30 days
            </button>
          </div>
          <button className="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
            🔄 Refresh
          </button>
        </div>

        {/* KPI Cards */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
          <div className="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition cursor-pointer">
            <div className="flex items-center justify-between mb-4">
              <div className="text-3xl">🧱</div>
              <div className="text-right">
                <div className="text-sm text-gray-500">Tiles Catalog</div>
                <div className="text-xs text-gray-400">Manage inventory</div>
              </div>
            </div>
            <div className="text-3xl font-bold text-purple-600">{stats.tiles.toLocaleString()}</div>
            <div className="text-sm text-gray-500 mt-1">📦 Total tile products</div>
          </div>

          <div className="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition cursor-pointer">
            <div className="flex items-center justify-between mb-4">
              <div className="text-3xl">📋</div>
              <div className="text-right">
                <div className="text-sm text-gray-500">Quotations</div>
                <div className="text-xs text-gray-400">Customer quotes</div>
              </div>
            </div>
            <div className="text-3xl font-bold text-green-600">{stats.quotes}</div>
            <div className="text-sm text-gray-500 mt-1">📄 All time quotes</div>
          </div>

          <div className="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition cursor-pointer">
            <div className="flex items-center justify-between mb-4">
              <div className="text-3xl">💳</div>
              <div className="text-right">
                <div className="text-sm text-gray-500">Invoices</div>
                <div className="text-xs text-gray-400">Sales transactions</div>
              </div>
            </div>
            <div className="text-3xl font-bold text-blue-600">{stats.invoices}</div>
            <div className="text-sm text-gray-500 mt-1">🧾 Total invoices</div>
          </div>

          <div className="bg-white rounded-xl shadow-lg p-6 hover:shadow-xl transition">
            <div className="flex items-center justify-between mb-4">
              <div className="text-3xl">💰</div>
              <div className="text-right">
                <div className="text-sm text-gray-500">Revenue</div>
                <div className="text-xs text-gray-400">This month</div>
              </div>
            </div>
            <div className="text-3xl font-bold text-green-600">₹{stats.revenue.toLocaleString()}</div>
            <div className="text-sm text-gray-500 mt-1">📈 Total earnings</div>
          </div>
        </div>

        {/* Quick Actions */}
        <div className="bg-white rounded-xl shadow-lg p-6">
          <h3 className="text-xl font-bold text-gray-800 mb-6">⚡ Quick Actions</h3>
          <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
            {quickActions.map((action, index) => (
              <button
                key={index}
                className={`${getColorClasses(action.color)} text-white p-4 rounded-lg transition duration-200 transform hover:scale-105`}
              >
                <div className="text-2xl mb-2">{action.icon}</div>
                <div className="text-sm font-medium">{action.name}</div>
              </button>
            ))}
          </div>
        </div>

        {/* Revenue Chart Placeholder */}
        <div className="mt-8 bg-white rounded-xl shadow-lg p-6">
          <div className="flex justify-between items-center mb-6">
            <h3 className="text-xl font-bold text-gray-800">📊 Revenue Overview</h3>
            <div className="text-right">
              <div className="text-2xl font-bold text-green-600">₹{stats.revenue.toLocaleString()}</div>
              <div className="text-sm text-gray-500">Current month</div>
            </div>
          </div>
          <div className="h-64 bg-gradient-to-r from-purple-100 to-blue-100 rounded-lg flex items-center justify-center">
            <div className="text-center">
              <div className="text-4xl mb-4">📈</div>
              <div className="text-lg font-semibold text-gray-600">Revenue Chart</div>
              <div className="text-sm text-gray-500">Analytics visualization will appear here</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// Main App Component
function App() {
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    // Check for existing login
    const savedUser = localStorage.getItem('user');
    if (savedUser) {
      setUser(JSON.parse(savedUser));
    }
    setLoading(false);
  }, []);

  const handleLogin = (userData) => {
    setUser(userData);
  };

  const handleLogout = () => {
    localStorage.removeItem('user');
    setUser(null);
  };

  if (loading) {
    return (
      <div className="min-h-screen flex items-center justify-center bg-gray-100">
        <div className="text-center">
          <div className="text-4xl mb-4">⏳</div>
          <div className="text-xl font-semibold">Loading...</div>
        </div>
      </div>
    );
  }

  return (
    <BrowserRouter>
      <Routes>
        <Route 
          path="/" 
          element={
            user ? 
            <Dashboard user={user} onLogout={handleLogout} /> : 
            <LoginPage onLogin={handleLogin} />
          } 
        />
        <Route 
          path="*" 
          element={<Navigate to="/" replace />} 
        />
      </Routes>
    </BrowserRouter>
  );
}

export default App;
