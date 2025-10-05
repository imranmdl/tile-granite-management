#!/usr/bin/env python3
"""
PHP Bridge - FastAPI service that serves PHP pages and handles routing
This bridge allows React frontend to work with PHP backend system
"""

import os
import subprocess
import tempfile
from pathlib import Path
from typing import Dict, Any
from fastapi import FastAPI, Request, Response
from fastapi.responses import HTMLResponse, FileResponse
from starlette.middleware.cors import CORSMiddleware
import asyncio

# Initialize FastAPI app
app = FastAPI(title="PHP Bridge Service")

# Add CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# PHP project paths
PHP_ROOT = Path("/app/public")
INCLUDES_PATH = Path("/app/includes")

class PHPExecutor:
    """Execute PHP files and return HTML output"""
    
    @staticmethod
    def execute_php(php_file: str, get_params: Dict = None, post_data: Dict = None, cookies: Dict = None) -> str:
        """Execute PHP file with given parameters"""
        
        # Create temporary PHP script that includes the target file
        temp_script = f"""<?php
// Set up environment
$_SERVER['REQUEST_METHOD'] = '{"POST" if post_data else "GET"}';
$_SERVER['REQUEST_URI'] = '/{php_file}';
$_SERVER['PHP_SELF'] = '/{php_file}';
$_SERVER['SCRIPT_NAME'] = '/{php_file}';

// Set GET parameters
$_GET = {str(get_params or {}).replace("'", '"')};

// Set POST parameters  
$_POST = {str(post_data or {}).replace("'", '"')};

// Set cookies
$_COOKIE = {str(cookies or {}).replace("'", '"')};

// Start session
session_start();

// Include the PHP file
chdir('{PHP_ROOT}');
include '{PHP_ROOT}/{php_file}';
?>"""
        
        try:
            # Write temp script
            with tempfile.NamedTemporaryFile(mode='w', suffix='.php', delete=False) as f:
                f.write(temp_script)
                temp_file = f.name
            
            # Execute PHP
            result = subprocess.run(
                ['php', temp_file],
                capture_output=True,
                text=True,
                cwd=str(PHP_ROOT),
                timeout=30
            )
            
            # Clean up
            os.unlink(temp_file)
            
            if result.returncode == 0:
                return result.stdout
            else:
                return f"<h1>PHP Error</h1><pre>{result.stderr}</pre>"
                
        except subprocess.TimeoutExpired:
            return "<h1>Timeout Error</h1><p>PHP script execution timed out</p>"
        except Exception as e:
            return f"<h1>Execution Error</h1><pre>{str(e)}</pre>"

# Initialize PHP executor
php = PHPExecutor()

@app.get("/")
async def root():
    """Serve main login page"""
    return HTMLResponse(php.execute_php("login.php"))

@app.get("/login")
async def login_get(request: Request):
    """Handle login page GET"""
    params = dict(request.query_params)
    return HTMLResponse(php.execute_php("login.php", get_params=params))

@app.post("/login")
async def login_post(request: Request):
    """Handle login page POST"""
    form_data = await request.form()
    post_data = dict(form_data)
    return HTMLResponse(php.execute_php("login.php", post_data=post_data))

@app.get("/dashboard")
@app.get("/index")
async def dashboard(request: Request):
    """Serve dashboard"""
    params = dict(request.query_params)
    return HTMLResponse(php.execute_php("index.php", get_params=params))

@app.get("/invoices")
async def invoices(request: Request):
    """Serve invoice list"""
    params = dict(request.query_params)
    return HTMLResponse(php.execute_php("invoice_list_enhanced.php", get_params=params))

@app.get("/invoice/{invoice_id}")
async def invoice_detail(invoice_id: int, request: Request):
    """Serve individual invoice"""
    params = dict(request.query_params)
    params['id'] = str(invoice_id)
    return HTMLResponse(php.execute_php("invoice_enhanced.php", get_params=params))

@app.post("/invoice/{invoice_id}")
async def invoice_update(invoice_id: int, request: Request):
    """Handle invoice updates"""
    form_data = await request.form()
    post_data = dict(form_data)
    get_params = {'id': str(invoice_id)}
    return HTMLResponse(php.execute_php("invoice_enhanced.php", get_params=get_params, post_data=post_data))

@app.get("/reports")
async def reports(request: Request):
    """Serve reports dashboard"""
    params = dict(request.query_params)
    return HTMLResponse(php.execute_php("reports_dashboard_new.php", get_params=params))

@app.get("/reports/sales")
async def sales_report(request: Request):
    """Serve sales report"""
    params = dict(request.query_params)
    return HTMLResponse(php.execute_php("report_sales_enhanced.php", get_params=params))

@app.get("/logout")
async def logout(request: Request):
    """Handle logout"""
    params = dict(request.query_params)
    return HTMLResponse(php.execute_php("logout.php", get_params=params))

# Generic route handler for any PHP file
@app.get("/{php_file:path}")
async def serve_php(php_file: str, request: Request):
    """Generic PHP file server"""
    
    # Add .php extension if not present
    if not php_file.endswith('.php'):
        php_file += '.php'
    
    # Check if file exists
    file_path = PHP_ROOT / php_file
    if not file_path.exists():
        return HTMLResponse("<h1>404 Not Found</h1><p>Page not found</p>", status_code=404)
    
    # Serve the PHP file
    params = dict(request.query_params)
    return HTMLResponse(php.execute_php(php_file, get_params=params))

@app.post("/{php_file:path}")
async def serve_php_post(php_file: str, request: Request):
    """Generic PHP POST handler"""
    
    # Add .php extension if not present
    if not php_file.endswith('.php'):
        php_file += '.php'
    
    # Check if file exists
    file_path = PHP_ROOT / php_file
    if not file_path.exists():
        return HTMLResponse("<h1>404 Not Found</h1><p>Page not found</p>", status_code=404)
    
    # Handle form data
    form_data = await request.form()
    post_data = dict(form_data)
    get_params = dict(request.query_params)
    
    return HTMLResponse(php.execute_php(php_file, get_params=get_params, post_data=post_data))

# Health check endpoint
@app.get("/api/health")
async def health_check():
    """Health check for the bridge service"""
    return {"status": "healthy", "service": "PHP Bridge", "php_root": str(PHP_ROOT)}

if __name__ == "__main__":
    import uvicorn
    uvicorn.run(app, host="0.0.0.0", port=8000, reload=True)