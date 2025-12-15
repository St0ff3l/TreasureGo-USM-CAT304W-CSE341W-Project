# TreasureGo

## Project Overview
TreasureGo is a web platform for buying and selling second-hand items. Users can post listings, search and filter products, and complete purchases. The interface is lightweight and responsive, optimized for both mobile and desktop experiences.

## Key Features
- **User system:** registration/login, profile management, avatar display, and session checks (PHP backend).
- **Product ecosystem:** item posting, category browsing, search results, and product card displays (plain HTML/CSS/JS).
- **Interaction:** direct search, hot/recommended tags, card hover/glow effects, and a mobile bottom quick bar.
- **Platform governance:** reporting, feedback, and support pages with placeholders for AI/review modules (modular design).
- **Orders & payments:** order lists and top-up entry point.
- **Security & privacy:** session-based access control, logout endpoint, and basic input encoding to mitigate XSS/SQL injection.

## Tech Stack & Structure
- **Backend:** PHP (session handling, authentication, API endpoints such as `Module_User_Account_Management/api/session_status.php`).
- **Frontend:** vanilla HTML, CSS, and JavaScript with responsive layouts and animations.
- **Resource organization:** modular directories, e.g., `Module_Product_Ecosystem`, `Module_User_Account_Management`, `Module_Platform_Governance_AI_Services`, and `Module_Transaction_Fund`.
