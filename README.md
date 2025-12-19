Gmail Manager - Project & Database Description
Project Overview
Gmail Manager is a full-stack web application that enables users to manage and analyze their Gmail inbox through a custom web interface. It integrates with Google's Gmail API and provides a comprehensive dashboard for email management, analytics, and organization.

Tech Stack:

Backend: Laravel 11 with PHP 8.2, PostgreSQL, and Redis
Frontend: Vanilla JavaScript with HTML/CSS
Infrastructure: Docker (Docker Compose with Nginx, PHP-FPM, PostgreSQL, Redis)
Core Features
Google OAuth2 Authentication - Secure login using Google credentials with access token management
Email Management - View, filter, and organize emails from Gmail inbox
Attachments - Browse and download email attachments with individual file retrieval
Sender Management - View all senders, filter emails by sender, bulk delete operations
Unsubscribe - Identify and unsubscribe from mailing lists automatically
Email Statistics - Comprehensive analytics on email activity, senders, and patterns
Deleted Emails - Track and view emails marked as deleted
Export Functionality - Export emails to PDF or CSV formats for offline access
Database Schema
The application uses PostgreSQL with three primary tables:

users
Stores user account and OAuth information

id - Primary key
name, email - User profile data
email_verified_at - Email verification timestamp
password - Hashed password (nullable for OAuth-only users)
google_id - Google account identifier
google_token - OAuth access token (encrypted)
google_refresh_token - Token for refresh (encrypted)
timestamps - created_at, updated_at
emails
Stores metadata for each email synchronized from Gmail

id - Primary key
gmail_id - Unique Gmail message ID
user_id - Foreign key to users (cascade delete)
from - Sender email address
to - Recipient email address
subject - Email subject line
date - Email date/time
remote_delete - Boolean flag for deletion status
thread_id - Gmail thread identifier
snippet - Email preview text
label_ids - Gmail labels as JSON
has_attachments - Boolean flag for attachment presence
timestamps - created_at, updated_at
Indexes: user_id, gmail_id, from (for efficient querying)
email_bodies
Stores the actual email content (separated for performance)

id - Primary key
email_id - Foreign key to emails (cascade delete)
body - Full email HTML/text content
timestamps - created_at, updated_at
Index: email_id
Supporting Tables
password_reset_tokens - Password reset token storage
sessions - User session management
jobs - Background job queue
cache - Cache storage
API Endpoints
All endpoints are prefixed with /api/gmail:

Authentication:

GET /auth-url - Generate Google OAuth URL
POST /login - User login
GET /callback - OAuth callback handler
Email Operations:

GET /emails - Retrieve paginated emails
GET /email/{emailId} - Get single email with body
GET /deleted - Get deleted emails
GET /attachments - Get emails with attachments
GET /attachment/download/{gmail_id}/{part_id} - Download attachment
Management:

GET /senders - List all senders with stats
POST /senders/show - Get emails from specific sender
POST /senders/delete - Delete all emails from sender
GET /statistics - Email analytics and statistics
POST /unsubscribe/emails - Unsubscribe from mailing lists
GET /emails/unsubscribe - Find unsubscribe emails
Export:

GET /export/pdf - Export all emails to PDF
GET /export/csv - Export all emails to CSV
Architecture Highlights
Separation of Concerns: Email bodies stored separately for optimized querying
OAuth Flow: Secure token-based authentication with refresh token support
Scalability: Redis caching for statistics and frequently accessed data
Data Integrity: Foreign key constraints with cascade deletes
Performance: Strategic indexing on user_id, gmail_id, and from fields
Type Safety: Laravel's Eloquent ORM with model relationships
