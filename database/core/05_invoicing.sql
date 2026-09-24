-- =============================================================================
-- Számlázás: ÁFA, rögzített fél-adatok, sztornó, hézagmentes sorszámozás
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- * Minden számlasor a saját ÁFA-kulcsával, nettó, ÁFA és bruttó összeggel
--   rögzül; a számla végösszege (total_amount) innentől bruttó.
-- * A vevő és az eladó neve, adószáma és címe a kiállításkor rögzül a
--   számlán, így egy későbbi partner- vagy cégadat-módosítás nem írja át.
-- * Egy számlát nem törlünk és nem „érvénytelenítünk”, hanem sztornó
--   számlával vonunk vissza, ami a raktári kiadást és a rendelést is
--   visszaállítja.
-- * A bizonylatszámokat a document_sequences számlálói adják ki.

CREATE TABLE IF NOT EXISTS document_sequences (
  doc_type VARCHAR(32) NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  last_number INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (doc_type, year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Számlasorok: ÁFA-kulcs és a három összeg.
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS vat_rate DECIMAL(5,2) NOT NULL DEFAULT 27.00 AFTER unit_price;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS net_amount DECIMAL(14,2) DEFAULT NULL AFTER line_total;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS vat_amount DECIMAL(14,2) DEFAULT NULL AFTER net_amount;
ALTER TABLE invoice_items ADD COLUMN IF NOT EXISTS gross_amount DECIMAL(14,2) DEFAULT NULL AFTER vat_amount;

-- Számlafej: összegek, teljesítés, fizetési mód, rögzített felek, sztornó.
ALTER TABLE invoices MODIFY status ENUM('unpaid','paid','cancelled','storno') NOT NULL DEFAULT 'unpaid';
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS invoice_type ENUM('normal','storno') NOT NULL DEFAULT 'normal' AFTER invoice_number;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS storno_of_id INT UNSIGNED DEFAULT NULL AFTER invoice_type;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS fulfilment_date DATE DEFAULT NULL AFTER issue_date;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS payment_method VARCHAR(20) NOT NULL DEFAULT 'transfer' AFTER due_date;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS net_total DECIMAL(14,2) DEFAULT NULL AFTER payment_method;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS vat_total DECIMAL(14,2) DEFAULT NULL AFTER net_total;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS extra_vat_rate DECIMAL(5,2) NOT NULL DEFAULT 27.00 AFTER payment_cost;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS buyer_name VARCHAR(200) DEFAULT NULL AFTER extra_vat_rate;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS buyer_tax_number VARCHAR(32) DEFAULT NULL AFTER buyer_name;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS buyer_address VARCHAR(500) DEFAULT NULL AFTER buyer_tax_number;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS seller_name VARCHAR(200) DEFAULT NULL AFTER buyer_address;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS seller_tax_number VARCHAR(32) DEFAULT NULL AFTER seller_name;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS seller_address VARCHAR(500) DEFAULT NULL AFTER seller_tax_number;
ALTER TABLE invoices ADD COLUMN IF NOT EXISTS seller_bank_account VARCHAR(64) DEFAULT NULL AFTER seller_address;
ALTER TABLE invoices ADD INDEX IF NOT EXISTS idx_invoices_storno_of (storno_of_id);
ALTER TABLE invoices
  ADD CONSTRAINT fk_invoices_storno_of FOREIGN KEY IF NOT EXISTS (storno_of_id) REFERENCES invoices (id);

-- A korábbi számlák kitöltése (csak egyszer: ahol még nincs érték), az
-- elsődleges pénznem pontosságára kerekítve: forintnál egész összegekre.
SET @cx_decimals := (
    SELECT CASE WHEN COALESCE(MAX(setting_value), 'HUF') IN ('HUF', 'JPY', 'KRW', 'ISK', 'CLP') THEN 0 ELSE 2 END
    FROM settings WHERE setting_key = 'currency.primary'
);

UPDATE invoice_items ii
JOIN products p ON p.id = ii.product_id
SET ii.vat_rate = p.vat_rate
WHERE ii.net_amount IS NULL;

UPDATE invoice_items
SET net_amount = line_total,
    vat_amount = ROUND(line_total * vat_rate / 100, @cx_decimals),
    gross_amount = line_total + ROUND(line_total * vat_rate / 100, @cx_decimals)
WHERE net_amount IS NULL;

UPDATE invoices i
JOIN (
    SELECT invoice_id, SUM(net_amount) AS net, SUM(vat_amount) AS vat
    FROM invoice_items GROUP BY invoice_id
) s ON s.invoice_id = i.id
SET i.net_total = s.net + i.shipping_cost + i.payment_cost,
    i.vat_total = s.vat + ROUND((i.shipping_cost + i.payment_cost) * i.extra_vat_rate / 100, @cx_decimals),
    i.total_amount = s.net + i.shipping_cost + i.payment_cost
                     + s.vat + ROUND((i.shipping_cost + i.payment_cost) * i.extra_vat_rate / 100, @cx_decimals)
WHERE i.net_total IS NULL;

UPDATE invoices SET fulfilment_date = issue_date WHERE fulfilment_date IS NULL;

-- A vevő adatai: a rendelés számlázási címe, különben a partner első címe.
UPDATE invoices i
JOIN partners p ON p.id = i.partner_id
LEFT JOIN orders o ON o.id = i.order_id
LEFT JOIN partner_addresses a ON a.id = COALESCE(
    o.billing_address_id,
    (SELECT MIN(pa.id) FROM partner_addresses pa WHERE pa.partner_id = p.id)
)
SET i.buyer_name = p.name,
    i.buyer_tax_number = p.tax_number,
    i.buyer_address = CASE WHEN a.id IS NULL THEN NULLIF(p.address, '')
                           ELSE CONCAT(a.postal_code, ' ', a.city, ', ', a.street,
                                       CASE WHEN a.country <> 'Magyarország' THEN CONCAT(', ', a.country) ELSE '' END) END
WHERE i.buyer_name IS NULL;

-- A régi „érvénytelenített” számlák a sztornó bevezetése előtt születtek;
-- azok maradnak cancelled állapotban, sztornó bizonylat nélkül.
