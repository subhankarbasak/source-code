---

````markdown
# 📁 Serving Files from Storage in Laravel 12

This document explains **how to serve files stored in Laravel's `storage/app/public` directory**, including:

- ✅ Symbolic link method (`php artisan storage:link`)
- ✅ Manual symbolic link (no command line)
- ✅ Route-based file serving (custom solution)
- ✅ Use case: DB-stored file paths like `fee_attachments/202620121_fee_payment00.jpg`

---

## 🔧 1. Method 1: Using `php artisan storage:link`

**Recommended by Laravel** for public access to files in `storage/app/public`.

### ✅ Steps:

1. Run:
   ```bash
   php artisan storage:link
````

2. Laravel creates a symbolic link:

   ```
   public/storage -> storage/app/public
   ```
3. Store your files in:

   ```
   storage/app/public/
   ```
4. Access them at:

   ```
   http://your-domain.com/storage/yourfile.jpg
   ```

---

## 🔧 2. Method 2: Manually Create Symbolic Link (Without Artisan)

If you can't run Artisan commands (e.g., on shared hosting), do it manually.

### ✅ Command:

```bash
ln -s /full/path/to/storage/app/public /full/path/to/public/storage
```

### 💡 Example:

From Laravel root:

```bash
ln -s storage/app/public public/storage
```

> ⚠️ Works only if your host/server supports symbolic links.

---

## 🔒 3. Method 3: Serve Files via Route (No Symbolic Link Required)

If symbolic links are **not supported** or you want to **control access**, use a custom route.

### ✅ Use Case

You have file paths like this stored in DB:

```
fee_attachments/202620121_fee_payment00.jpg
```

Which physically reside in:

```
storage/app/public/fee_attachments/202620121_fee_payment00.jpg
```

---

### 🛠️ Setup

#### A. Add Route in `routes/web.php`:

```php
use Illuminate\Support\Facades\Response;

Route::get('/file-storage/{path}', function ($path) {
    $fullPath = storage_path('app/public/' . $path);

    if (!file_exists($fullPath)) {
        abort(404);
    }

    return Response::file($fullPath);
})->where('path', '.*'); // Allow nested folders
```

#### B. Generate URL in Controller or Blade:

```php
$url = url('/file-storage/' . $filePathFromDb);
```

In Blade:

```blade
<a href="{{ url('/file-storage/' . $attachment->path) }}" target="_blank">Download</a>
```

---

### 🔐 (Optional) Secure Access with Auth

Only allow logged-in users to view/download files:

```php
Route::get('/file-storage/{path}', function ($path) {
    if (!auth()->check()) {
        abort(403);
    }

    $fullPath = storage_path('app/public/' . $path);

    if (!file_exists($fullPath)) {
        abort(404);
    }

    return response()->file($fullPath);
})->where('path', '.*')->middleware('auth');
```

---

## ✅ Summary: Which Method to Use?

| Method                     | Symbolic Link Needed | Good For Public Access | Access Control | Notes                                          |
| -------------------------- | -------------------- | ---------------------- | -------------- | ---------------------------------------------- |
| `php artisan storage:link` | ✅ Yes                | ✅ Yes                  | ❌ No           | Easiest & Laravel default                      |
| Manual Link                | ✅ Yes                | ✅ Yes                  | ❌ No           | Good for shared hosting                        |
| Route-Based                | ❌ No                 | ✅ Optional             | ✅ Yes          | Flexible, good for private or logged-in access |

---

## 📝 Notes

* Make sure your `.env` is configured properly:

  ```env
  FILESYSTEM_DISK=public
  APP_URL=https://your-domain.com
  ```

* Ensure the `public` disk is correctly set in `config/filesystems.php`:

  ```php
  'public' => [
      'driver' => 'local',
      'root' => storage_path('app/public'),
      'url' => env('APP_URL') . '/storage',
      'visibility' => 'public',
  ],
  ```

* Ensure correct permissions:

  ```bash
  chmod -R 775 storage
  chmod -R 775 bootstrap/cache
  ```

---

## 👨‍🔧 Need Help?

If you’re storing files **outside** `storage/app/public` or want to serve **private documents**, you’ll need to use Laravel’s file streaming or download responses.

Let us know if that’s your case.

```

---

If you're asking **how to serve files from Laravel's `storage` directory via a route**, instead of using `php artisan storage:link`, you can create a **custom route** that reads and returns files manually.

---

### ✅ Use Route to Serve Storage Files

This is useful if you can't or don't want to use symbolic links (e.g., on limited hosting environments).

---

### 🔧 Step-by-Step: Serve Files via Route

#### 1. Create a route in `routes/web.php`:

```php
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Response;

Route::get('/storage/{filename}', function ($filename) {
    $path = storage_path('app/public/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return Response::file($path);
});
```

---

#### 2. Place files in `storage/app/public/`

Example:

```bash
storage/app/public/example.jpg
```

---

#### 3. Access it via:

```
http://your-domain.com/storage/example.jpg
```

---

### 🔐 Optional: Add MIME type headers

If you want to include proper headers:

```php
return response()->file($path, [
    'Content-Type' => mime_content_type($path)
]);
```

---

### 🛡️ Optional: Add Access Control

You can add middleware or auth checks in the route to restrict access:

```php
Route::get('/storage/{filename}', function ($filename) {
    if (!auth()->check()) {
        abort(403);
    }

    $path = storage_path('app/public/' . $filename);

    if (!file_exists($path)) {
        abort(404);
    }

    return response()->file($path);
});
```

---

### ✅ When to Use This Approach

* On shared hosting (no `ln -s` access).
* If you want to **restrict file access** or log downloads.
* When symbolic linking isn't supported by your environment.

Let me know if you're serving folders or dynamic paths — I can adjust the route logic for nested files (e.g., `/storage/folder/filename.jpg`).

