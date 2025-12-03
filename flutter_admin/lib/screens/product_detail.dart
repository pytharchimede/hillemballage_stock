import 'package:flutter/material.dart';
import '../api.dart';
import '../widgets/admin_scaffold.dart';

class ProductDetailScreen extends StatefulWidget {
  const ProductDetailScreen({super.key});

  @override
  State<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends State<ProductDetailScreen> {
  Map<String, dynamic>? product;
  bool _loading = true;
  List<dynamic> _stocks = const [];
  List<dynamic> _depots = const [];
  Map<String, dynamic>? _me;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (product == null) {
      final args = ModalRoute.of(context)?.settings.arguments;
      if (args is Map<String, dynamic>) {
        product = args;
        _load();
      } else {
        _loading = false;
      }
    }
  }

  Future<void> _load() async {
    if (product == null) return;
    setState(() => _loading = true);
    await Api.init();
    _me = await Api.ensureMe();
    final stocks = await Api.productStocks(product!['id'] as int);
    final depots = await Api.depots();
    setState(() {
      _stocks = stocks;
      _depots = depots;
      _loading = false;
    });
  }

  Future<void> _openStockInDialog() async {
    if (product == null) return;
    int? depotId;
    int qty = 1;
    await showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          title: const Text('Entrée en stock'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<int>(
                decoration: const InputDecoration(labelText: 'Dépôt'),
                items: _depots
                    .map((d) => DropdownMenuItem<int>(
                          value: (d['id'] as num).toInt(),
                          child: Text('${d['name']} (${d['code'] ?? ''})'),
                        ))
                    .toList(),
                onChanged: (v) => depotId = v,
              ),
              const SizedBox(height: 8),
              TextFormField(
                decoration: const InputDecoration(labelText: 'Quantité'),
                keyboardType: TextInputType.number,
                onChanged: (v) => qty = int.tryParse(v) ?? 1,
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('Annuler'),
            ),
            ElevatedButton(
              onPressed: () async {
                if (depotId == null || qty <= 0) return;
                final ok = await Api.stockIn(
                  depotId: depotId!,
                  productId: (product!['id'] as num).toInt(),
                  quantity: qty,
                );
                if (mounted) Navigator.of(ctx).pop();
                if (ok) {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                          content: Text('Entrée en stock enregistrée')),
                    );
                  }
                  _load();
                } else {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                          content: Text("Échec de l'entrée en stock")),
                    );
                  }
                }
              },
              child: const Text('Valider'),
            ),
          ],
        );
      },
    );
  }

  Future<void> _openTransferDialog() async {
    if (product == null) return;
    int? fromId;
    int? toId;
    int qty = 1;
    await showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          title: const Text('Transférer stock'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<int>(
                decoration: const InputDecoration(labelText: 'De'),
                items: _depots
                    .map((d) => DropdownMenuItem<int>(
                          value: (d['id'] as num).toInt(),
                          child: Text('${d['name']} (${d['code'] ?? ''})'),
                        ))
                    .toList(),
                onChanged: (v) => fromId = v,
              ),
              const SizedBox(height: 8),
              DropdownButtonFormField<int>(
                decoration: const InputDecoration(labelText: 'Vers'),
                items: _depots
                    .map((d) => DropdownMenuItem<int>(
                          value: (d['id'] as num).toInt(),
                          child: Text('${d['name']} (${d['code'] ?? ''})'),
                        ))
                    .toList(),
                onChanged: (v) => toId = v,
              ),
              const SizedBox(height: 8),
              TextFormField(
                decoration: const InputDecoration(labelText: 'Quantité'),
                keyboardType: TextInputType.number,
                onChanged: (v) => qty = int.tryParse(v) ?? 1,
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('Annuler'),
            ),
            ElevatedButton(
              onPressed: () async {
                if (fromId == null ||
                    toId == null ||
                    fromId == toId ||
                    qty <= 0) return;
                final ok = await Api.stockTransfer(
                  fromDepotId: fromId!,
                  toDepotId: toId!,
                  productId: (product!['id'] as num).toInt(),
                  quantity: qty,
                );
                if (mounted) Navigator.of(ctx).pop();
                if (ok) {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Transfert effectué')),
                    );
                  }
                  _load();
                } else {
                  if (mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('Transfert échoué')),
                    );
                  }
                }
              },
              child: const Text('Valider'),
            ),
          ],
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final p = product;
    final perms = (_me?['permissions'] as Map?) ?? const {};
    bool _perm(Map? m) => m != null && m['edit'] == true;
    final canEditStocks = _perm(perms['*']) || _perm(perms['stocks']);
    final canTransfer = _perm(perms['*']) || _perm(perms['transfers']);
    return AdminScaffold(
      title: p != null ? (p['name'] ?? 'Produit') : 'Produit',
      currentRoute: '/products',
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : Padding(
              padding: const EdgeInsets.all(12.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (p != null)
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            '${p['name'] ?? ''} — SKU: ${p['sku'] ?? ''}',
                            style: const TextStyle(fontWeight: FontWeight.w600),
                          ),
                        ),
                        const SizedBox(width: 8),
                        if (canEditStocks)
                          OutlinedButton.icon(
                            icon: const Icon(Icons.arrow_downward),
                            onPressed: _openStockInDialog,
                            label: const Text('Entrée en stock'),
                          ),
                        const SizedBox(width: 8),
                        if (canTransfer)
                          OutlinedButton.icon(
                            icon: const Icon(Icons.sync_alt),
                            onPressed: _openTransferDialog,
                            label: const Text('Transférer'),
                          ),
                      ],
                    ),
                  const SizedBox(height: 12),
                  Expanded(
                    child: _stocks.isEmpty
                        ? const Center(child: Text('Aucun stock par dépôt.'))
                        : ListView.separated(
                            itemCount: _stocks.length,
                            separatorBuilder: (_, __) =>
                                const SizedBox(height: 6),
                            itemBuilder: (ctx, i) {
                              final s = _stocks[i] as Map<String, dynamic>;
                              final name = s['depot_name'] ?? '';
                              final code = s['depot_code'] ?? '';
                              final qty = s['quantity'] ?? 0;
                              return Card(
                                elevation: 1,
                                child: ListTile(
                                  leading: const Icon(Icons.warehouse_outlined),
                                  title: Text(
                                      '$name ${code != '' ? '($code)' : ''}'),
                                  trailing: Text('$qty',
                                      style: const TextStyle(
                                          fontWeight: FontWeight.bold)),
                                ),
                              );
                            },
                          ),
                  ),
                ],
              ),
            ),
    );
  }
}
