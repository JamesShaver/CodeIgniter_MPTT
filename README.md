# Forum Categories MPTT for CodeIgniter 4


A modern **Modified Preorder Tree Traversal (MPTT)** implementation for managing hierarchical forum categories in **CodeIgniter 4**.  

This library is designed to be a forward-thinking replacement for traditional adjacency lists by providing:  

- Efficient **read operations** (descendants, siblings, paths)  
- Full **create, move, copy, delete** operations with transactional safety  
- **Soft deletes** and **hard deletes**  
- **Tree integrity checks** and a **rebuild method** for recovery  
- Integrated **CodeIgniter caching** to minimize database queries  
- Easy UI helpers (`toList()`, `toSelect()`)  

---

## 🚀 Features

- Add nodes anywhere in the tree (`root`, `child`, `sibling`)  
- Move or copy entire subtrees  
- Retrieve descendants, siblings, and parent relationships  
- Soft deletes (`delete()`) and permanent deletes (`hardDelete()`)  
- `verifyTree()` to check consistency of the tree  
- `rebuildTree()` to restore a broken hierarchy  
- Caching via CodeIgniter’s cache drivers (APCu, Redis, File, etc.)  

---

## 📦 Installation

Copy the class file into your application:

```
app/Libraries/CategoryMPTT.php
```

---

## 🗄️ Database Schema

Example schema for the `forum_categories` table:

```sql
CREATE TABLE `forum_categories` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `parent_id` INT UNSIGNED DEFAULT NULL,
  `lft` INT UNSIGNED NOT NULL,
  `rgt` INT UNSIGNED NOT NULL,
  `depth` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## ⚙️ Usage

### Load the library

```php
use App\Libraries\CategoryMPTT;

$mptt = new CategoryMPTT();
```

---

### Add Categories

```php
// Root category
$programmingId = $mptt->add([
    'name' => 'Programming',
    'slug' => 'programming'
]);

// Child category under Programming
$phpId = $mptt->add([
    'name' => 'PHP',
    'slug' => 'php'
], $programmingId, 'lastChild');

// Another child category
$ci4Id = $mptt->add([
    'name' => 'CodeIgniter 4',
    'slug' => 'codeigniter-4'
], $phpId, 'lastChild');
```

---

### Move or Copy Nodes

```php
// Move "CodeIgniter 4" under "Programming"
$mptt->move($ci4Id, $programmingId, 'lastChild');

// Copy "PHP" subtree under "Programming"
$newPhpId = $mptt->copy($phpId, $programmingId, 'lastChild');
```

---

### Delete Categories

```php
// Soft delete (marks as deleted_at)
$mptt->delete($phpId);

// Hard delete (removes node + descendants, adjusts tree)
$mptt->hardDelete($ci4Id);
```

---

### Retrieve Information

```php
// Get all descendants of "Programming"
$children = $mptt->getDescendants($programmingId);

// Get parent of "CodeIgniter 4"
$parent = $mptt->getParent($ci4Id);

// Get siblings of "PHP"
$siblings = $mptt->getSiblings($phpId);

// Breadcrumb path for "CodeIgniter 4"
$path = $mptt->getPath($ci4Id);
```

---

### UI Helpers

```php
// Generate <ul>/<li> list
echo $mptt->toList();

// Generate <select> options with indentation
$options = $mptt->toSelect();
```

---

### Tree Integrity

```php
// Verify tree consistency
if (! $mptt->verifyTree()) {
    echo "Tree is broken!";
}

// Rebuild tree if necessary
$mptt->rebuildTree();
```

---

## 🧪 Caching

This library uses CodeIgniter’s caching system.  
- Default cache key: `categories_tree`  
- Default TTL: 300 seconds  
- Cache is automatically invalidated after write operations.  

You can configure cache drivers in `app/Config/Cache.php`.

---

## 🛡️ Transactions

All write operations (`add`, `move`, `delete`, `hardDelete`, `copy`, `update`, `rebuildTree`) run inside **InnoDB transactions** for data integrity.  

---

## 📌 Example: Rendering a Category Select

```php
$mptt = new CategoryMPTT();
$options = $mptt->toSelect();

echo '<select name="category_id">';
foreach ($options as $id => $label) {
    echo "<option value='{$id}'>{$label}</option>";
}
echo '</select>';
```

---

## 📌 Example: Breadcrumb Navigation

```php
$path = $mptt->getPath($ci4Id);

echo '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
foreach ($path as $node) {
    echo "<li class='breadcrumb-item'>{$node['name']}</li>";
}
echo '</ol></nav>';
```

---

## ✅ Summary

This MPTT library gives you:  
- **Fast reads** for hierarchical forum categories  
- **Safe writes** with transactions  
- **Flexibility** with soft/hard deletes  
- **Recovery tools** for rebuilding corrupted trees  
- **CI4 caching** to minimize database hits  

Perfect for forums, blogs, menus, product categories, and any nested data structure.

---

## 📋 Requirements

- PHP 8.1+  
- CodeIgniter 4.5+  
- MySQL 8.0+ (InnoDB engine recommended)  

---

## 📜 License

This project is open-sourced software licensed under the **MIT license**.

---
