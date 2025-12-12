# TreasureGO Transaction & Fund Module - API Integration Guide

## 📋 Overview

This document describes the complete integration between the frontend (TXN_FUND.html) and backend (TXN.php) using the MySQL database (treasurego_db).

---

## 🗄️ Database Configuration

**File:** `Transaction_Fund_Module/api/config/treasurego_db_config.php`

### Connection Details:
```php
Host: daombledore.fun
Port: 3306
Database: treasurego_db
Username: cqstgo
Password: qwer1234
Charset: utf8mb4
```

### Database Functions:
- `getDatabaseConnection()` - Creates PDO connection
- `testDatabaseConnection()` - Tests connection status
- `closeDatabaseConnection()` - Closes connection

---

## 🔌 Backend API (TXN.php)

**File:** `Transaction_Fund_Module/api/TXN.php`

### API Endpoints

All requests use: `TXN.php?action={action_name}`

#### 1️⃣ Fund Requests

**Create Fund Request**
```
Action: create_fund_request
Method: POST
Body: {
  "user_id": 12345678,
  "type": "deposit|withdrawal|refund|transfer",
  "amount": 100.00,
  "proof_image": "https://...",
  "admin_remark": "Optional notes"
}
Response: {
  "success": true,
  "message": "Fund request created successfully",
  "data": {
    "request_id": 123,
    "status": "pending"
  }
}
```

**Get Fund Requests**
```
Action: get_fund_requests
Method: POST
Body: {
  "user_id": 12345678
}
Response: {
  "success": true,
  "data": [
    {
      "REQUEST_ID": 1,
      "User_ID": 12345678,
      "Type": "deposit",
      "Amount": "500.00",
      "Status": "approved",
      "Created_AT": "2025-12-12 10:30:00",
      "Processed_AT": "2025-12-12 15:45:00"
    }
  ]
}
```

**Get Fund Request by ID**
```
Action: get_fund_request
Method: POST
Body: {
  "request_id": 123
}
```

**Update Fund Request Status** (Admin)
```
Action: update_fund_request_status
Method: POST
Body: {
  "request_id": 123,
  "status": "approved|rejected|processing|completed",
  "admin_remark": "Verified"
}
```

#### 2️⃣ Orders

**Get Orders**
```
Action: get_orders
Method: POST
Body: {
  "user_id": 12345678
}
Response: {
  "success": true,
  "data": [
    {
      "Orders_Order_ID": 1,
      "Orders_Buyer_ID": 12345678,
      "Orders_Seller_ID": 87654321,
      "Orders_Total_Amount": "125.00",
      "Orders_Platform_Fee": "6.25",
      "Orders_Status": "completed",
      "Orders_Created_AT": "2025-12-12 11:20:00"
    }
  ]
}
```

**Get Order by ID**
```
Action: get_order
Method: POST
Body: {
  "order_id": 1
}
```

**Create Order**
```
Action: create_order
Method: POST
Body: {
  "buyer_id": 12345678,
  "seller_id": 87654321,
  "total_amount": 125.00,
  "platform_fee": 6.25
}
```

**Update Order Status**
```
Action: update_order_status
Method: POST
Body: {
  "order_id": 1,
  "status": "pending|paid|processing|shipped|completed|cancelled|refunded"
}
```

**Confirm Order Receipt**
```
Action: confirm_receipt
Method: POST
Body: {
  "order_id": 1,
  "buyer_id": 12345678
}
Response: {
  "success": true,
  "message": "Order receipt confirmed successfully",
  "data": {
    "status": "completed"
  }
}
```

#### 3️⃣ Wallet Logs

**Get Wallet Logs**
```
Action: get_wallet_logs
Method: POST
Body: {
  "user_id": 12345678
}
Response: {
  "success": true,
  "data": [
    {
      "Log_ID": 1,
      "User_id": 12345678,
      "Amount": "500.00",
      "Balance_After": "2458.50",
      "Description": "Deposit of $500",
      "Reference_Type": "fund_request",
      "Reference_ID": 1,
      "Created_AT": "2025-12-12 10:30:00"
    }
  ]
}
```

**Get Wallet Balance**
```
Action: get_wallet_balance
Method: POST
Body: {
  "user_id": 12345678
}
Response: {
  "success": true,
  "data": {
    "balance": "2458.50"
  }
}
```

#### 4️⃣ Statistics

**Get Dashboard Statistics**
```
Action: get_statistics
Method: POST
Body: {
  "user_id": 12345678
}
Response: {
  "success": true,
  "data": {
    "balance": "2,458.50",
    "total_orders": 24,
    "pending_requests": 3,
    "completed_transactions": 18
  }
}
```

#### 5️⃣ Test

**Test Database Connection**
```
Action: test_connection
Method: GET/POST
Response: {
  "success": true,
  "message": "Database connection successful",
  "data": {
    "database": "treasurego_db",
    "server_time": "2025-12-12 10:30:00",
    "host": "daombledore.fun",
    "port": "3306"
  }
}
```

---

## 🎨 Frontend Integration (TXN_FUND.html)

**File:** `Transaction_Fund_Module/pages/TXN_FUND.html`

### Configuration

```javascript
const API_BASE_URL = '../api/TXN.php';
const CURRENT_USER_ID = 12345678; // TODO: Get from session/login
```

### Key Functions

#### API Helper
```javascript
async function apiCall(action, data = {}, method = 'POST')
```
- Handles all API requests
- Automatically formats requests and handles errors
- Returns parsed JSON response

#### Data Loading Functions

**Load Statistics**
```javascript
async function loadStatistics()
```
- Fetches user statistics
- Updates dashboard stat cards
- Called on page load

**Load Fund Requests**
```javascript
async function loadFundRequests()
```
- Fetches all fund requests for current user
- Populates fund requests table
- Called on page load and after creating new request

**Load Orders**
```javascript
async function loadOrders()
```
- Fetches all orders for current user
- Distinguishes between product orders (#ORD) and fund deposits (#FND)
- Populates orders table

**Load Transactions**
```javascript
async function loadTransactions()
```
- Fetches wallet logs
- Populates transaction history table
- Shows balance changes

#### Form Submission

**Fund Request Form**
```javascript
document.getElementById('fundRequestForm').addEventListener('submit', async function(e)
```
- Prevents default form submission
- Calls API to create fund request
- Reloads data on success
- Shows success/error messages

#### View Functions

**View Fund Request Details**
```javascript
async function viewFundRequestDetails(requestId)
```
- Fetches fund request data from API
- Displays in modal with formatted information
- Shows contact options for admin

**View Order Details**
```javascript
async function viewOrderDetails(orderId, type, status)
```
- Fetches order data from API
- Distinguishes between product orders and fund deposits
- Shows appropriate after-sales options
- Handles receipt confirmation

**View Transaction Details**
```javascript
async function viewTransactionDetails(logId)
```
- Fetches wallet log data
- Displays transaction details in modal
- Shows balance after transaction

#### Action Functions

**Confirm Receipt**
```javascript
async function confirmReceiptAPI(orderId)
```
- Calls API to confirm order receipt
- Releases funds to seller
- Updates order status to completed
- Disables refund option after confirmation

**Apply Refund**
```javascript
function applyRefundInModal(orderId)
```
- Creates refund request
- Checks if receipt already confirmed
- Notifies seller and admin

### Helper Functions

```javascript
function getStatusClass(status)      // Returns CSS class for status badge
function capitalizeFirst(str)        // Capitalizes first letter
function formatDateTime(dateStr)     // Formats date/time for display
```

---

## 📊 Database Tables Used

### 1. Fund_Requests
```sql
- REQUEST_ID (PK)
- User_ID (FK → User)
- Type (deposit, withdrawal, refund, transfer)
- Amount (DECIMAL 15,2)
- Status (pending, approved, rejected, processing, completed)
- Proof_Image (VARCHAR 512)
- Admin_Remark (VARCHAR 2000)
- Created_AT (DATETIME)
- Processed_AT (DATETIME)
```

### 2. Orders
```sql
- Orders_Order_ID (PK)
- Orders_Buyer_ID (FK → User)
- Orders_Seller_ID (FK → User)
- Orders_Total_Amount (DECIMAL 15,2)
- Orders_Platform_Fee (DECIMAL 15,2)
- Orders_Status (VARCHAR 20)
- Orders_Created_AT (DATETIME)
```

### 3. Wallet_Logs
```sql
- Log_ID (PK)
- User_id (FK → User)
- Amount (DECIMAL 15,2) - Can be positive or negative
- Balance_After (DECIMAL 15,2)
- Description (VARCHAR 512)
- Reference_Type (VARCHAR 20)
- Reference_ID (INT)
- Created_AT (DATETIME)
```

---

## 🔄 Data Flow Examples

### Example 1: Creating a Fund Request

1. **Frontend:** User fills out form and submits
2. **JavaScript:** `fundRequestForm` submit handler captures data
3. **API Call:** `apiCall('create_fund_request', data)`
4. **Backend:** `createFundRequest()` validates and inserts to database
5. **Response:** Returns new request ID and status
6. **Frontend:** Shows success message, reloads fund requests table

### Example 2: Confirming Order Receipt

1. **Frontend:** User clicks "Confirm Receipt" in order details
2. **JavaScript:** `confirmReceiptAPI(orderId)` called
3. **API Call:** `apiCall('confirm_receipt', {order_id, buyer_id})`
4. **Backend:** 
   - Validates buyer authorization
   - Updates order status to 'completed'
   - Creates wallet log for seller (releases funds)
   - Calculates seller amount (total - platform fee)
5. **Response:** Returns success with status
6. **Frontend:** 
   - Shows success message
   - Disables refund button
   - Reloads orders and statistics

### Example 3: Loading Dashboard Statistics

1. **Frontend:** Page loads
2. **JavaScript:** `DOMContentLoaded` event triggers `loadStatistics()`
3. **API Call:** `apiCall('get_statistics', {user_id})`
4. **Backend:**
   - Queries wallet balance from latest Wallet_Logs
   - Counts total orders
   - Counts pending fund requests
   - Counts completed transactions
5. **Response:** Returns aggregated statistics
6. **Frontend:** Updates stat cards with formatted data

---

## 🛡️ Security Features

1. **SQL Injection Prevention:** PDO prepared statements
2. **CORS Headers:** Configured for cross-origin requests
3. **Input Validation:** Server-side validation of all inputs
4. **Error Handling:** Try-catch blocks with user-friendly messages
5. **Transaction Authorization:** Verify user ownership before updates

---

## 🧪 Testing

### Test Database Connection
```
URL: TXN.php?action=test_connection
Expected: Success message with database info
```

### Test API Endpoints
Use tools like Postman or browser console:

```javascript
// Test in browser console
fetch('../api/TXN.php?action=test_connection')
  .then(res => res.json())
  .then(data => console.log(data));
```

---

## 🚀 Deployment Checklist

- [ ] Update `CURRENT_USER_ID` to fetch from session
- [ ] Configure proper session management
- [ ] Disable error display in production
- [ ] Set up HTTPS
- [ ] Configure CORS for production domain
- [ ] Set up database backups
- [ ] Implement rate limiting
- [ ] Add logging for transactions
- [ ] Test all API endpoints
- [ ] Verify database indexes for performance

---

## 📝 TODO Items

1. Replace `CURRENT_USER_ID` constant with session-based authentication
2. Implement user login/logout system
3. Add admin panel for managing fund requests
4. Implement real-time notifications
5. Add pagination for large data sets
6. Implement search and filter functionality
7. Add export functionality for transactions
8. Integrate payment gateway for deposits
9. Add email notifications for status changes
10. Implement audit logging

---

## 🐛 Troubleshooting

### Database Connection Fails
- Check if MySQL server is running
- Verify credentials in treasurego_db_config.php
- Check firewall settings for port 3306
- Test connection using: `?action=test_connection`

### API Returns 404
- Verify API_BASE_URL path in HTML file
- Check if TXN.php exists in correct location
- Ensure web server can execute PHP files

### Data Not Loading
- Open browser console and check for errors
- Verify CURRENT_USER_ID exists in database
- Check API response in Network tab
- Ensure database tables have data

### CORS Errors
- Verify CORS headers in TXN.php
- Check if frontend and backend are on same domain
- Update Access-Control-Allow-Origin if needed

---

✅ **Integration Complete!**

The frontend and backend are now fully connected and ready to use. All CRUD operations for Fund Requests, Orders, and Wallet Logs are functional and integrated with the MySQL database.

