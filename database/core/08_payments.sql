-- =============================================================================
-- Kifizetések (részfizetés) és a nyitott tételek
-- =============================================================================
-- Idempotens: a migrate.php minden futáskor újrafuttatja.
--
-- Egy számlára (kimenőre vagy bejövőre) több kifizetés is érkezhet: átutalás,
-- kártya, vagy egy pénztárbizonylat. A számla paid_amount oszlopa a
-- kifizetések összege; a státusz akkor lesz 'paid', ha ez eléri a végösszeget,
-- addig 'unpaid' marad (a felület "részben fizetve"-ként mutatja, ha már van
-- rajta befizetés). A nyitott egyenleg: total_amount - paid_amount.

CREATE TABLE IF NOT EXISTS payments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  invoice_id INT UNSIGNED DEFAULT NULL,
  incoming_invoice_id INT UNSIGNED DEFAULT NULL,
  amount DECIMAL(14,2) NOT NULL,
  paid_on DATE NOT NULL,
  method VARCHAR(20) NOT NULL DEFAULT 'transfer',
  note VARCHAR(255) DEFAULT NULL,
  cash_voucher_id INT UNSIGNED DEFAULT NULL,
  created_by INT UNSIGNED DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_payments_invoice (invoice_id, paid_on),
  KEY idx_payments_incoming (incoming_invoice_id, paid_on),
  UNIQUE KEY uniq_payments_voucher (cash_voucher_id),
  CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices (id),
  CONSTRAINT fk_payments_incoming FOREIGN KEY (incoming_invoice_id) REFERENCES incoming_invoices (id),
  CONSTRAINT fk_payments_voucher FOREIGN KEY (cash_voucher_id) REFERENCES cash_vouchers (id),
  CONSTRAINT fk_payments_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT chk_payments_document CHECK ((invoice_id IS NULL) <> (incoming_invoice_id IS NULL)),
  CONSTRAINT chk_payments_amount CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE invoices ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER total_amount;
ALTER TABLE incoming_invoices ADD COLUMN IF NOT EXISTS paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER total_amount;

-- ---------------------------------------------------------------------------
-- Visszatöltés a korábbi, "egyben kifizetve" adatokból
-- ---------------------------------------------------------------------------
-- 1. Minden számlához kötött pénztárbizonylat egy kifizetés lesz (egyszer:
--    a bizonylat egyedi kulcs).
INSERT INTO payments (invoice_id, incoming_invoice_id, amount, paid_on, method, cash_voucher_id, created_by, created_at)
SELECT v.invoice_id, v.incoming_invoice_id, v.amount, LEAST(v.voucher_date, CURDATE()), 'cash', v.id, v.created_by, v.created_at
FROM cash_vouchers v
WHERE (v.invoice_id IS NOT NULL) <> (v.incoming_invoice_id IS NOT NULL)
  AND v.amount > 0
  AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.cash_voucher_id = v.id);

-- 2. A kifizetettnek jelölt, de bizonylat nélküli (vagy nem teljes) számlák
--    maradéka egy kifizetés a számla fizetési módjával, a határidő napján (ha
--    az már elmúlt, különben ma). Utána a számla fedezett, így újrafuttatva
--    nem kerül be újra.
INSERT INTO payments (invoice_id, amount, paid_on, method, note, created_at)
SELECT i.id, i.total_amount - COALESCE(s.paid, 0), LEAST(i.due_date, CURDATE()), COALESCE(i.payment_method, 'transfer'),
       'Korábbi "kifizetve" jelölés', NOW()
FROM invoices i
LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payments WHERE invoice_id IS NOT NULL GROUP BY invoice_id) s ON s.invoice_id = i.id
WHERE i.status = 'paid' AND i.invoice_type = 'normal' AND i.total_amount - COALESCE(s.paid, 0) > 0.004;

INSERT INTO payments (incoming_invoice_id, amount, paid_on, method, note, created_at)
SELECT i.id, i.total_amount - COALESCE(s.paid, 0), LEAST(i.due_date, CURDATE()), 'transfer',
       'Korábbi "kifizetve" jelölés', NOW()
FROM incoming_invoices i
LEFT JOIN (SELECT incoming_invoice_id, SUM(amount) AS paid FROM payments WHERE incoming_invoice_id IS NOT NULL GROUP BY incoming_invoice_id) s
       ON s.incoming_invoice_id = i.id
WHERE i.status = 'paid' AND i.total_amount - COALESCE(s.paid, 0) > 0.004;

-- 3. A paid_amount mindig a kifizetések összege.
UPDATE invoices i
LEFT JOIN (SELECT invoice_id, SUM(amount) AS paid FROM payments WHERE invoice_id IS NOT NULL GROUP BY invoice_id) s ON s.invoice_id = i.id
SET i.paid_amount = COALESCE(s.paid, 0);

UPDATE incoming_invoices i
LEFT JOIN (SELECT incoming_invoice_id, SUM(amount) AS paid FROM payments WHERE incoming_invoice_id IS NOT NULL GROUP BY incoming_invoice_id) s
       ON s.incoming_invoice_id = i.id
SET i.paid_amount = COALESCE(s.paid, 0);
