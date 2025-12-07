<?php
// Redirection vers le dossier public (utile sous WAMP quand on visite /hill_new/)
header('Location: public/');
exit;

// Supplier fiche export (PDF) with branded layout
$router->map('GET', '/api/v1/suppliers/[i:id]/export', function ($id) use ($db) {
    requireUserCan('admin');

    // Fetch supplier
    $stmt = $db->prepare('SELECT * FROM suppliers WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $supplier = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$supplier) {
        http_response_code(404);
        echo json_encode(['error' => 'Supplier not found']);
        return;
    }

    // Create branded PDF
    $pdf = hill_pdf_create('Fiche fournisseur');
    $pdf->AddPage();

    // Header: Title + Supplier Name
    $title = '<h1 style="margin:0;padding:0;color:#222;font-size:20px;">Fiche fournisseur</h1>';
    $name = htmlspecialchars($supplier['name'] ?? '');
    $subtitle = '<div style="font-size:14px;color:#444;">' . $name . '</div>';
    $pdf->writeHTML($title . $subtitle, true, false, true, false, '');

    // QR code top-right
    $qrData = json_encode([
        'type' => 'supplier',
        'id' => $supplier['id'],
        'name' => $supplier['name'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $style = [
        'border' => 0,
        'fgcolor' => [0, 0, 0],
        'bgcolor' => false,
    ];
    // x=175,y=18,w=22 keeps to the top-right similar to user/product fiche
    $pdf->write2DBarcode($qrData, 'QRCODE,H', 175, 18, 22, 22, $style);

    // Separator
    $pdf->Ln(2);
    $pdf->writeHTML('<hr style="height:1px;border:0;background:#e5e7eb;margin:6px 0;"/>', true, false, true, false, '');

    // Content blocks
    $blocks = [];
    $blocks[] = '<b>Contact</b><br/>'
        . htmlspecialchars(trim(($supplier['contact_name'] ?? '') . ' ' . ($supplier['contact_title'] ?? '')))
        . '<br/>'
        . htmlspecialchars($supplier['phone'] ?? '')
        . '<br/>'
        . htmlspecialchars($supplier['email'] ?? '');

    $addr = [];
    foreach (['address_line1', 'address_line2', 'city', 'postcode', 'country'] as $k) {
        if (!empty($supplier[$k])) {
            $addr[] = $supplier[$k];
        }
    }
    $blocks[] = '<b>Adresse</b><br/>' . htmlspecialchars(implode(', ', $addr));

    $geo = [];
    if (!empty($supplier['lat']) && !empty($supplier['lon'])) {
        $geo[] = 'Lat: ' . $supplier['lat'];
        $geo[] = 'Lon: ' . $supplier['lon'];
    }
    $blocks[] = '<b>Localisation</b><br/>' . htmlspecialchars(implode(' | ', $geo));

    $logi = [];
    foreach (['delivery_days', 'lead_time', 'min_order_amount'] as $k) {
        if (!empty($supplier[$k])) {
            $logi[] = ucfirst(str_replace('_', ' ', $k)) . ': ' . $supplier[$k];
        }
    }
    $blocks[] = '<b>Logistique</b><br/>' . htmlspecialchars(implode(' | ', $logi));

    $bank = [];
    foreach (['iban', 'bic', 'payment_terms'] as $k) {
        if (!empty($supplier[$k])) {
            $bank[] = strtoupper($k) . ': ' . $supplier[$k];
        }
    }
    $blocks[] = '<b>Banque</b><br/>' . htmlspecialchars(implode(' | ', $bank));

    $cats = [];
    if (!empty($supplier['categories'])) {
        // categories stored as CSV or JSON; try decode JSON first
        $cval = $supplier['categories'];
        $decoded = json_decode($cval, true);
        if (is_array($decoded)) {
            $cats = array_filter(array_map('strval', $decoded));
        } else {
            $cats = array_filter(array_map('trim', explode(',', (string)$cval)));
        }
    }
    if ($cats) {
        $badges = [];
        foreach ($cats as $c) {
            $badges[] = '<span style="display:inline-block;padding:2px 6px;margin:2px;border-radius:10px;background:#eef2ff;color:#4338ca;font-size:10px;">' . htmlspecialchars($c) . '</span>';
        }
        $blocks[] = '<b>Catégories</b><br/>' . implode(' ', $badges);
    }

    if (!empty($supplier['notes'])) {
        $blocks[] = '<b>Notes</b><br/>' . nl2br(htmlspecialchars($supplier['notes']));
    }

    $leftCol = [];
    $rightCol = [];
    foreach ($blocks as $i => $b) {
        if ($i % 2 === 0) {
            $leftCol[] = $b;
        } else {
            $rightCol[] = $b;
        }
    }
    $colsHtml = '<table cellpadding="6" cellspacing="0" width="100%"><tr>'
        . '<td width="50%" valign="top">' . implode('<br/><br/>', $leftCol) . '</td>'
        . '<td width="50%" valign="top">' . implode('<br/><br/>', $rightCol) . '</td>'
        . '</tr></table>';
    $pdf->writeHTML($colsHtml, true, false, true, false, '');

    // Optional logo/photo top-left without affecting layout (draw after HTML)
    $photoPaths = [];
    if (!empty($supplier['photo'])) {
        $photoPaths[] = __DIR__ . '/public/uploads/' . $supplier['photo'];
        $photoPaths[] = __DIR__ . '/uploads/' . $supplier['photo'];
    }
    $photoPath = null;
    foreach ($photoPaths as $pp) {
        if ($pp && file_exists($pp)) {
            $photoPath = $pp;
            break;
        }
    }
    if ($photoPath) {
        // place at x=12,y=18,w=24 similar to user/product
        $pdf->Image($photoPath, 12, 18, 24, 24, '', '', '', false, 300, '', false, false, 0);
    }

    // Output
    $fileName = 'fiche_fournisseur_' . ($supplier['id']) . '.pdf';
    $pdf->Output($fileName, 'I');
});
