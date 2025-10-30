# Admin Panel Documentation
## Weekly Accomplishment System - Admin Side

---

## Overview
Ang admin panel ay ginawa para sa administrators na makapag-view ng lahat ng logs at accomplishments ng mga students/OJTs. Simple lang at walang need ng database modifications.

---

## Features

### 1. **Admin Login** (`admin_login.php`)
- Simple authentication gamit ang hardcoded credentials
- Walang database needed para sa admin accounts
- Default credentials:
  - **Username:** `admin`
  - **Password:** `admin123`

**Important:** Palitan ang password sa `admin_login.php` file para sa security!

### 2. **Admin Dashboard** (`admin_dashboard.php`)
Main dashboard na may:
- **Statistics Cards:**
  - Total Students
  - Total Log Entries
  - Total Hours Logged

- **Filter Options:**
  - Search by username, email, or task
  - Filter by specific student
  - Date range filter (from/to)
  
- **Logs Table:**
  - View all student accomplishment logs
  - Clickable student names (leads to detailed view)
  - Shows date, time in/out, hours, tasks completed
  - Display limit: 200 recent logs

### 3. **All Students Page** (`admin_students.php`)
Student directory na may:
- Card-based layout ng lahat ng students
- Statistics per student:
  - Total logs
  - Total hours
  - Active/Inactive status
- Last log date
- Member since date
- Clickable cards (leads to detailed view)

### 4. **Student Detail Page** (`admin_student_detail.php`)
Detailed view ng individual student:
- User information (avatar, username, email, join date)
- Statistics:
  - Total logs
  - Total hours
  - First log date
  - Latest log date
- Recent Weekly Summary table
- Complete list of all accomplishment logs

---

## How to Use

### Installation
1. Upload ang 4 PHP files sa php folder:
   - `admin_login.php`
   - `admin_dashboard.php`
   - `admin_students.php`
   - `admin_student_detail.php`

2. Make sure na existing na yung `db.php` file for database connection

### Accessing the Admin Panel
1. Open browser and go to: `http://your-domain/php/admin_login.php`
2. Login using the admin credentials
3. Navigate through the dashboard

### Changing Admin Password
Open `admin_login.php` and modify this line:
```php
$ADMIN_PASSWORD = "admin123"; // Change this to a secure password
```

### Adding More Admin Accounts (Optional)
Para mag-add ng multiple admins, pwede mong i-modify ang authentication logic sa `admin_login.php`:

```php
// Example: Multiple admins
$ADMIN_ACCOUNTS = [
    'admin' => 'password123',
    'admin2' => 'anotherpassword',
    'supervisor' => 'supervisor123'
];

if (isset($ADMIN_ACCOUNTS[$username]) && $ADMIN_ACCOUNTS[$username] === $password) {
    // Login success
}
```

---

## Navigation Flow

```
admin_login.php
    └─> admin_dashboard.php
            ├─> admin_students.php
            │       └─> admin_student_detail.php
            └─> admin_student_detail.php (via clickable username)
```

---

## Security Notes

1. **Change default password immediately!**
2. Ang admin panel ay separate sa student login system
3. Session-based authentication (separate admin session)
4. SQL injection protected (gamit prepared statements)
5. XSS protected (gamit htmlspecialchars)

---

## Database Tables Used

Ang admin panel ay gumagamit lang ng existing tables:
- `users` - for student information
- `weekly_accomplishments` - for logs and tasks

**Walang need ng bagong table or database modifications!**

---

## Responsive Design

- Mobile-friendly ang lahat ng pages
- Grid layouts adjust based on screen size
- Touch-friendly buttons and links

---

## Troubleshooting

### Can't login?
- Check if tama ang username/password
- Check if session ay enabled sa server

### No data showing?
- Verify database connection (`db.php`)
- Check if may existing records sa `weekly_accomplishments` table

### Page not found?
- Make sure nasa correct directory ang files
- Check file permissions

---

## Future Enhancements (Optional)

Kung gusto mo pang dagdagan in the future:
1. Export to Excel/PDF functionality
2. Advanced analytics and charts
3. Email notifications
4. Admin activity logs
5. Multiple admin roles/permissions
6. Date range exports
7. Bulk actions

---

## Design Features

- Modern gradient UI (purple/blue theme)
- Card-based layouts
- Hover animations
- Responsive tables
- Icon-based navigation
- Clean and professional look

---

## File Sizes
- `admin_login.php` - ~5KB
- `admin_dashboard.php` - ~14KB
- `admin_students.php` - ~10KB
- `admin_student_detail.php` - ~11KB

**Total: ~40KB** - Very lightweight!

---

## Browser Compatibility
- Chrome ✓
- Firefox ✓
- Safari ✓
- Edge ✓
- Mobile browsers ✓

---

## Support
Para sa questions or modifications, basahin lang ang comments sa code. Self-explanatory naman ang mga functions.

---

**Enjoy the admin panel! 🎉**
