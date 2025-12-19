# Gmail Manager Frontend

A web-based client for managing Gmail emails with OAuth2 authentication.

## Features

- **Google OAuth2 Login** - Secure authentication with Google
- **Inbox Management** - View and manage emails
- **Attachments** - Browse and download email attachments
- **Senders Management** - View senders and bulk delete by sender
- **Unsubscribe** - Find and unsubscribe from mailing lists
- **Statistics** - Comprehensive email analytics
- **Deleted Emails** - View emails marked as deleted
- **Export** - Export all emails to HTML or CSV

## Structure

```
frontend/
├── index.html           # Main HTML page
├── css/
│   ├── style.css       # Main styles (empty - add your own)
│   ├── auth.css        # Auth styles (empty)
│   ├── dashboard.css   # Dashboard styles (empty)
│   ├── emails.css      # Email styles (empty)
│   └── attachments.css # Attachment styles (empty)
├── js/
│   ├── config.js       # Configuration and token management
│   ├── api.js          # API client wrapper
│   ├── auth.js         # Authentication logic
│   ├── inbox.js        # Inbox functionality
│   ├── attachments.js  # Attachments functionality
│   ├── senders.js      # Senders functionality
│   ├── unsubscribe.js  # Unsubscribe functionality
│   ├── statistics.js   # Statistics functionality
│   ├── deleted.js      # Deleted emails functionality
│   ├── export.js       # Export functionality
│   └── app.js          # Main app initialization
└── README.md          # This file
```

## Setup

1. Place this frontend folder in your web server root
2. Update `CONFIG.API_BASE_URL` in `js/config.js` if your API is on a different host
3. Update your Google OAuth redirect URI to point to `/api/gmail/callback`
4. Add your own CSS styling to the empty CSS files

## API Integration

All API calls go through `js/api.js` which provides:
- Automatic token handling (Bearer auth)
- Error handling
- Request/response formatting
- All Gmail API endpoints

## Authentication Flow

1. User clicks "Login with Google"
2. Redirected to Google OAuth consent screen
3. After approval, redirected to `/api/gmail/callback?code=...`
4. Backend exchanges code for access token
5. Frontend stores token in localStorage
6. Token used for all subsequent API calls

## Usage

### Login
```javascript
await loginWithGoogle(); // Redirects to Google OAuth
```

### Get Emails
```javascript
const response = await API.getEmails(pageSize, pageToken);
const emails = response.emails;
```

### Get Statistics
```javascript
const response = await API.getStatistics(refresh, exportFormat);
const stats = response.statistics;
```

### Export All Emails
```javascript
const response = await API.exportAllEmailsPdf();
// Browser will download the file
```

## Customization

Add your CSS to the empty files:
- `css/style.css` - Main styling
- `css/auth.css` - Login screen styling
- `css/dashboard.css` - Dashboard layout
- `css/emails.css` - Email list styling
- `css/attachments.css` - Attachments styling

## Notes

- All API calls require authentication (Bearer token)
- Token is stored in localStorage (key: `gmail_manager_token`)
- CORS must be enabled on backend if frontend is on different domain
- Use HTTPS in production for security
