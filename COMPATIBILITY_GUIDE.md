# Guía de Compatibilidad EasyOCR: Dolibarr v14 → v23

## Resumen Ejecutivo

Se han identificado **3 cambios CRÍTICOS** que deben corregirse para compatibilidad con Dolibarr v16+, y varios cambios recomendados para máxima compatibilidad futura hasta v23.

### Cambios Críticos Identificados

| # | Problema | Archivos Afectados | Prioridad |
|---|----------|--------------------|-----------|
| 1 | `$user->rights->easyocr->X` → `$user->hasRight()` | 6 de 7 archivos PHP | 🔴 CRÍTICO |
| 2 | Filtro `'s.fournisseur=1'` → `'(s.fournisseur:=:1)'` en `select_company()` | invoices.php, templates.php | 🔴 CRÍTICO |
| 3 | Menús con perms usando sintaxis antigua de rights | modEasyocr.class.php | 🔴 CRÍTICO |

---

## 1. Sistema de Permisos: `hasRight()` 🔴 CRÍTICO

### Descripción del Cambio
Desde Dolibarr v16, el acceso a permisos migró de propiedades dinámicas a un método dedicado. La sintaxis antigua aún funciona por retrocompatibilidad mediante `DolDeprecationHandler`, pero genera warnings de deprecación y fallará en versiones futuras.

### Sintaxis Antigua (DEPRECADA)
```php
$user->rights->easyocr->read
$user->rights->easyocr->write
$user->rights->easyocr->delete
```

### Sintaxis Nueva (REQUERIDA)
```php
$user->hasRight('easyocr', 'read')
$user->hasRight('easyocr', 'write')
$user->hasRight('easyocr', 'delete')
```

### Mapeo de Nombres Internos
Dolibarr mapea internamente nombres franceses a ingleses:
- `lire` → `read`
- `creer` → `write` (también `create`)
- `supprimer` → `delete`

### Firma de `hasRight()`
```php
public function hasRight(string $module, string $permlevel1, string $permlevel2 = ''): bool
```
- 2 niveles de permisos soportados
- `$permlevel2` es opcional (para permisos anidados como `$user->hasRight('facture', 'invoice_advance', 'undelete')`)

### Archivos a Modificar en EasyOCR

#### `modEasyocr.class.php` — Perms de Menús
```php
// ANTES (líneas de los menús):
$this->menu[$r++] = array(
    'perms' => '$user->rights->easyocr->read',
    ...
);

// DESPUÉS:
$this->menu[$r++] = array(
    'perms' => '$user->hasRight("easyocr", "read")',
    ...
);
```
> **Nota:** En los descriptores de módulo, los perms son strings que se evalúan con `verifCond()`. Usar comillas dobles dentro de comillas simples.

#### `invoices.php` (~4 instancias)
```php
// ANTES:
if (!$user->rights->easyocr->read) accessforbidden();
if ($user->rights->easyocr->delete) { ... }

// DESPUÉS:
if (!$user->hasRight('easyocr', 'read')) accessforbidden();
if ($user->hasRight('easyocr', 'delete')) { ... }
```

#### `tool.php` (~1 instancia)
```php
// ANTES:
if (!$user->rights->easyocr->write) accessforbidden();

// DESPUÉS:
if (!$user->hasRight('easyocr', 'write')) accessforbidden();
```

#### `templates.php` (~5 instancias)
```php
// ANTES:
if (!$user->rights->easyocr->read) accessforbidden();
if ($user->rights->easyocr->write) { ... }
if ($user->rights->easyocr->delete) { ... }

// DESPUÉS:
if (!$user->hasRight('easyocr', 'read')) accessforbidden();
if ($user->hasRight('easyocr', 'write')) { ... }
if ($user->hasRight('easyocr', 'delete')) { ... }
```

#### `templates_view.php` (~1 instancia)
```php
// ANTES:
if (!$user->rights->easyocr->write) accessforbidden();

// DESPUÉS:
if (!$user->hasRight('easyocr', 'write')) accessforbidden();
```

#### `ajax/ajax_easyocr.php` (~3 instancias)
```php
// ANTES:
if (!$user->rights->easyocr->read) { ... }
if (!$user->rights->easyocr->write) { ... }

// DESPUÉS:
if (!$user->hasRight('easyocr', 'read')) { ... }
if (!$user->hasRight('easyocr', 'write')) { ... }
```

### Herramienta Automatizada
Dolibarr incluye un Rector en `dev/tools/rector/` que puede automatizar esta migración.

### Retrocompatibilidad
- La sintaxis antigua funciona en v14-v23 gracias al trait `DolDeprecationHandler`
- Genera `E_USER_DEPRECATED` en versiones modernas
- `hasRight()` existe desde v14+, por lo que la migración es segura para `need_dolibarr_version = array(14, 0, 0)`

---

## 2. `isModEnabled()` ✅ CORRECTO

### Descripción del Cambio
Función estándar para verificar si un módulo está activo. Reemplaza `$conf->modulename->enabled`.

### Estado en EasyOCR
El módulo **ya usa correctamente** `isModEnabled('easyocr')` en `modEasyocr.class.php` para los campos `enabled` de menús. ✅

### Mapeo de Nombres Deprecados de Módulos
Si se usa `isModEnabled()` con módulos de Dolibarr, usar los nombres nuevos:

| Nombre Antiguo | Nombre Nuevo |
|----------------|--------------|
| `actioncomm` | `agenda` |
| `adherent` | `member` |
| `banque` | `bank` |
| `categorie` | `category` |
| `commande` | `order` |
| `contrat` | `contract` |
| `entrepot` | `stock` |
| `expedition` | `shipping` |
| `facture` | `invoice` |
| `ficheinter` | `intervention` |
| `projet` | `project` |
| `propale` | `propal` |
| `socpeople` | `contact` |

---

## 3. `llxHeader()` / `llxFooter()` ✅ SIN CAMBIOS

### Firma Actual (hasta 13 parámetros)
```php
function llxHeader(
    $head = '',           // CSS adicional
    $title = '',          // Título de la página
    $help_url = '',       // URL de ayuda
    $target = '',         // Target
    $disablejs = 0,       // Deshabilitar JS
    $disablehead = 0,     // Deshabilitar head
    $arrayofjs = '',      // Array de JS adicionales
    $arrayofcss = '',     // Array de CSS adicionales
    $morequerystring = '',// Query string adicional
    $morecssonbody = '',  // CSS adicional en body
    $replacemainareaby = '',
    $disablenofollow = 0,
    $disablenoindex = 0
)
```

### Estado en EasyOCR
Las llamadas con 8 parámetros en `tool.php`, `invoices.php`, `templates.php`, `templates_view.php` son **totalmente compatibles**. ✅

---

## 4. `dol_get_fiche_head()` ✅ SIN CAMBIOS

### Estado en EasyOCR
El uso en `admin/setup.php`:
```php
dol_get_fiche_head($head, 'settings', $langs->trans("EasyOcr"), -1, 'easyocr@easyocr')
```
Es **completamente estándar y compatible**. ✅ Firma estable con hasta 11 parámetros.

---

## 5. CSRF Token `newToken()` ✅ SIN CAMBIOS

### Estado en EasyOCR
El uso de `newToken()` en formularios de todas las páginas es **correcto**. ✅

El define `NOTOKENRENEWAL` en `ajax_easyocr.php` es el patrón estándar para endpoints AJAX. ✅

Disponible desde v14+.

---

## 6. `GETPOST()` / `GETPOSTISSET()` / `GETPOSTINT()` ✅ SIN CAMBIOS

### Descripción
- `GETPOST($name, $type)`: Estable. Tipos: `'int'`, `'alpha'`, `'alphanohtml'`, `'aZ09'`, `'restricthtml'`, `'array'`, `'array:int'`, `'password'`, `'nohtml'`
- `GETPOSTISSET($name)`: Estable
- `GETPOSTINT($name)`: Wrapper de conveniencia añadido en versiones recientes. Equivale a `GETPOST($name, 'int')`

### Estado en EasyOCR
Todos los usos son **compatibles**. ✅

### Recomendación
Considerar usar `GETPOSTINT()` para parámetros enteros en código nuevo (menor verbosidad).

---

## 7. `setEventMessages()` ✅ SIN CAMBIOS

### Firma
```php
function setEventMessages($mesg, $mesgs, $style = 'mesgs', $messagekey = '', $noduplicate = 0)
```

### Estado en EasyOCR
Todos los usos son **compatibles**. ✅

---

## 8. `$db->plimit()` ✅ SIN CAMBIOS

### Firma
```php
$db->plimit($limit, $offset = 0)
```

### Estado en EasyOCR
El uso `$db->plimit($limit + 1, $offset)` es el **patrón estándar** de Dolibarr. ✅

---

## 9. `EcmFiles` ✅ SIN CAMBIOS

### Estado en EasyOCR
El uso en `ajax_easyocr.php`:
```php
$ecmfile = new EcmFiles($db);
$ecmfile->filepath = $rel_dir;
$ecmfile->filename = $filename;
$ecmfile->fullpath_orig = $fullpath;
$ecmfile->gen_or_uploaded = 'uploaded';
$ecmfile->src_object_type = '...';
$ecmfile->src_object_id = $id;
$ecmfile->create($user);
```
Es **completamente compatible**. ✅

### Nota
El campo `agenda_id` fue añadido en versiones recientes pero es opcional.

---

## 10. `FactureFournisseur::addline()` ✅ SIN CAMBIOS

### Firma Completa (24 parámetros)
```php
public function addline(
    $desc, $pu, $txtva, $txlocaltax1, $txlocaltax2, $qty,
    $fk_product = 0, $remise_percent = 0, $date_start = '', $date_end = '',
    $ventil = 0, $info_bits = 0, $price_base_type = 'HT', $type = 0,
    $rang = -1, $notrigger = 0, $array_options = [], $fk_unit = null,
    $origin_id = 0, $pu_devise = 0, $ref_supplier = '',
    $special_code = 0, $fk_parent_line = 0, $fk_remise_except = 0
)
```

### Estado en EasyOCR
El uso con 6 parámetros `$facture->addline($line_desc, $total_ht, $tva_tx, 0, 0, 1)` es **seguro** y mapea a `($desc, $pu, $txtva, $txlocaltax1, $txlocaltax2, $qty)`. ✅

---

## 11. `PaiementFourn` ✅ VERIFICAR UNA PROPIEDAD

### `create()` — Firma
```php
public function create($user, $closepaidcontrib = 0)
```
El uso `$paiement->create($user, 1)` es **correcto**. ✅

### `addPaymentToBank()` — Firma (9 parámetros)
```php
public function addPaymentToBank(
    $user, $mode, $label, $accountid, 
    $emetteur_nom, $emetteur_banque,
    $notrigger = 0, $accountancycode = '', $addbankurl = ''
)
```
El uso con 6 parámetros es **compatible**. ✅

### ⚠️ Propiedad Deprecada
```php
// DEPRECADO:
$paiement->bank_account = $id;

// NUEVO:
$paiement->fk_account = $id;
```
**Verificar en `ajax_easyocr.php`** qué propiedad se usa para asignar la cuenta bancaria. Si usa `bank_account`, cambiar a `fk_account`.

---

## 12. `get_exdir()` ✅ SIN CAMBIOS

### Firma
```php
function get_exdir($id, $level, $ecm, $useref, $object, $modulepart)
```

### Estado en EasyOCR
```php
get_exdir($newId, 2, 0, 0, $facture, 'invoice_supplier')
```
Es **el patrón estándar** y completamente compatible. ✅

---

## 13. `dol_buildpath()` ✅ SIN CAMBIOS

### Firma
```php
function dol_buildpath($path, $type = 0)
```

| Tipo | Descripción | Ejemplo |
|------|-------------|---------|
| `0` | Ruta del sistema de archivos | `/var/www/dolibarr/htdocs/custom/...` |
| `1` | URL relativa | `/custom/easyocr/js/scripts.js` |
| `2` | URL completa (con http) | `http://localhost/custom/easyocr/...` |
| `3` | URL completa (con http) | Similar a 2 |

### Estado en EasyOCR
```php
// tool.php
dol_buildpath('/custom/easyocr/js/scripts.js', 1)

// easyocr.lib.php
dol_buildpath('/easyocr/admin/setup.php', 1)
```
Ambos usos son **estándar y estables**. ✅

---

## 14. `complete_head_from_modules()` ✅ SIN CAMBIOS

### Firma
```php
function complete_head_from_modules(
    $conf, $langs, $object, &$head, &$h, 
    $type, $mode = 'add', $filtervalue = ''
)
```

### Estado en EasyOCR
```php
complete_head_from_modules($conf, $langs, null, $head, $h, 'easyocr_admin')
```
Es **el patrón universal** usado en 50+ archivos lib del core. ✅

### Nota
Versiones recientes añadieron un 8° parámetro `$filtervalue` (p.ej. `'external'`) para posicionamiento de pestañas externas. Es opcional.

---

## 15. `load_fiche_titre()` ✅ SIN CAMBIOS

### Estado en EasyOCR
```php
load_fiche_titre($title, $linkback, 'title_setup')
```
Función estable, sin cambios de firma. ✅

---

## 16. `top_httphead()` ✅ SIN CAMBIOS

### Estado en EasyOCR
```php
top_httphead('application/json')
```
Usado en `ajax_easyocr.php`. Función estable. ✅

---

## 17. `DolibarrModules` (Clase Base) ✅ SIN CAMBIOS

### Estado en EasyOCR
- Constructor: `parent::__construct($db)` — Estable ✅
- `_load_tables()` — Estable ✅ 
- `_init()` — Estable ✅
- `numero`, `rights_class`, `family`, `module_position`, `name`, `description`, `version`, `const_name`, `picto` — Todas propiedades estables ✅
- Sistema de permisos `$this->rights[]` — Estable ✅
- Sistema de menús `$this->menu[]` — Estable (pero actualizar campo `perms`, ver Ítem 1) ⚠️

---

## 18. `Form::select_company()` 🔴 CRÍTICO

### Descripción del Cambio
El parámetro de filtro SQL cambió de sintaxis directa a **Universal Search Criteria** (formato con separadores `:`). El cambio se aplica porque `select_company()` internamente usa `forgeSQLFromUniversalSearchCriteria()` para procesar los filtros.

### Sintaxis Antigua (DEPRECADA / ROTA en v20+)
```php
$form->select_company($selected, 'name', 's.fournisseur=1', 'SelectThirdParty', ...);
```

### Sintaxis Nueva (REQUERIDA)
```php
$form->select_company($selected, 'name', '(s.fournisseur:=:1)', 'SelectThirdParty', ...);
```

### Formato Universal Search Criteria
```
(campo:operador:valor)
```

Operadores soportados:
| Operador | SQL equivalente |
|----------|----------------|
| `:=:` | `=` |
| `:!=:` | `!=` / `<>` |
| `:<:` | `<` |
| `:>:` | `>` |
| `:<=:` | `<=` |
| `:>=:` | `>=` |
| `:IN:` | `IN (...)` |
| `:LIKE:` | `LIKE` |
| `:NOT LIKE:` | `NOT LIKE` |

Ejemplos:
```php
'(s.fournisseur:=:1)'              // Proveedores
'(s.client:IN:1,2,3)'             // Clientes tipo 1, 2 o 3
'(s.status:=:1) AND (s.client:=:1)' // Activos y clientes
```

### Archivos a Modificar en EasyOCR

#### `invoices.php`
```php
// ANTES:
$form->select_company($searchSupplier, 'search_supplier', 's.fournisseur=1', 'SelectThirdParty', ...);

// DESPUÉS:
$form->select_company($searchSupplier, 'search_supplier', '(s.fournisseur:=:1)', 'SelectThirdParty', ...);
```

#### `templates.php`
```php
// ANTES:
$form->select_company(..., 's.fournisseur=1', ...);

// DESPUÉS:
$form->select_company(..., '(s.fournisseur:=:1)', ...);
```

### Retrocompatibilidad
- La sintaxis antigua puede funcionar en v14-v18 pero **falla silenciosamente o causa errores** en v19+
- La nueva sintaxis funciona desde v16+
- Dado que `need_dolibarr_version = array(14, 0, 0)`, considerar hacer una detección de versión o simplemente requerir v16+

---

## 19. `Form::textwithpicto()` ✅ COMPATIBLE (mejora recomendada)

### Firma
```php
public function textwithpicto(
    $text, $htmltext, $direction = 1, $type = 'help',
    $extracss = '', $noencodehtmltext = 0, $notabs = 3,
    $tooltiptrigger = '', $forcenowrap = 0
)
```

### Estado en EasyOCR
```php
$form->textwithpicto($langs->trans('EasyOcrHelpAndShortcuts'), $helpText, 1, 'help', '', 0, 3)
```
7 parámetros — **compatible**. ✅

### Mejora Recomendada (no obligatoria)
El tercer parámetro `$direction` acepta ahora strings además de enteros:
- `0` → `'info'`
- `1` → `'help'`

El módulo ya usa `'help'` como 4° parámetro, y el 3° es `1` (que equivale a dirección). Esto es correcto y no requiere cambios.

---

## 20. Compatibilidad PHP 8.x

### PHP 8.0
- **Paso de `null` a funciones internas**: Functions como `trim()`, `strlen()`, `strpos()`, `array_key_exists()` ya no aceptan `null` sin warnings.
  - **Verificar** en todo el módulo que no se pase `null` a estas funciones
  - Usar el operador null coalescing: `trim($var ?? '')`

### PHP 8.1
- **`#[\ReturnTypeWillChange]`**: Si el módulo implementa interfaces como `ArrayAccess`, `Countable`, etc., añadir esta anotación
- **Enums**: No afecta al módulo actual
- **Fibers**: No afecta al módulo actual
- **Intersection types**: No afecta al módulo actual

### PHP 8.2
- **Propiedades dinámicas deprecadas**: PHP 8.2 depreca la creación de propiedades dinámicas en clases.
  - Dolibarr maneja esto con el trait `DolDeprecationHandler` en sus clases core
  - **El módulo EasyOCR no crea propiedades dinámicas**, por lo que no hay impacto directo ✅
  - Si se instancian objetos Dolibarr y se asignan propiedades no declaradas, generará warnings

### PHP 8.3+
- **Sin impacto conocido** para el módulo actual

### Dolibarr Target PHP Version
- El análisis estático de Dolibarr (Phan) apunta a PHP 8.2: `"target_php_version" => '8.2'`
- La base de código de Dolibarr aún mantiene compatibilidad con PHP 7.0 en algunas áreas

### Recomendaciones Específicas para EasyOCR
1. Verificar que ninguna variable que pueda ser `null` se pase a `trim()`, `strlen()`, etc.
2. En `ajax_easyocr.php`, verificar que los valores de GETPOST no sean `null` antes de pasarlos a funciones de string
3. Usar `??` (null coalescing) defensivamente:
   ```php
   // ANTES:
   $value = trim($someVar);
   
   // DESPUÉS (PHP 8.x safe):
   $value = trim($someVar ?? '');
   ```

---

## Resumen de Acciones Requeridas

### 🔴 CRÍTICO — Hacer Inmediatamente

| Acción | Archivo(s) | Cambio |
|--------|-----------|--------|
| Migrar permisos | Todos los PHP | `$user->rights->easyocr->X` → `$user->hasRight('easyocr', 'X')` |
| Migrar perms menús | modEasyocr.class.php | `'$user->rights->easyocr->read'` → `'$user->hasRight("easyocr", "read")'` |
| Migrar filtro select_company | invoices.php, templates.php | `'s.fournisseur=1'` → `'(s.fournisseur:=:1)'` |

### 🟡 RECOMENDADO — Para Máxima Compatibilidad

| Acción | Archivo(s) | Cambio |
|--------|-----------|--------|
| ~~`bank_account` vs `fk_account`~~ | ajax_easyocr.php | Ya usa `$paiement->fk_account` ✅ |
| Null safety PHP 8.x | Todos | Añadir `?? ''` en llamadas a `trim()`, `strlen()`, etc. |
| Considerar `GETPOSTINT()` | Todos | Usar para parámetros enteros |

### ✅ SIN CAMBIOS REQUERIDOS

Las siguientes APIs son completamente estables entre v14 y v23:
- `llxHeader()` / `llxFooter()`
- `dol_get_fiche_head()` / `dol_get_fiche_end()`
- `newToken()` / `NOTOKENRENEWAL`
- `GETPOST()` / `GETPOSTISSET()`
- `setEventMessages()`
- `$db->plimit()`
- `EcmFiles`
- `FactureFournisseur::addline()`
- `PaiementFourn::create()` / `PaiementFourn::addPaymentToBank()`
- `get_exdir()`
- `dol_buildpath()`
- `complete_head_from_modules()`
- `load_fiche_titre()`
- `top_httphead()`
- `DolibarrModules` (clase base)
- `Form::textwithpicto()`

---

## Versión Mínima Recomendada

Dado que `hasRight()` y la nueva sintaxis de `select_company` están disponibles desde v16, se recomienda actualizar:

```php
// En modEasyocr.class.php
$this->need_dolibarr_version = array(16, 0, 0);  // Antes: array(14, 0, 0)
```

Esto garantiza que todas las migraciones de este documento funcionen sin problemas.

---

*Guía generada tras investigación exhaustiva del repositorio Dolibarr/dolibarr en GitHub, analizando los cambios de API entre las versiones 14 y 23.*
