import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';
import '../api.dart';
import 'package:url_launcher/url_launcher.dart';

class ProductsScreen extends StatefulWidget {
  const ProductsScreen({super.key});

  @override
  State<ProductsScreen> createState() => _ProductsScreenState();
}

class _ProductsScreenState extends State<ProductsScreen> {
  final _qCtrl = TextEditingController();
  bool _onlyInStock = false;
  bool _loading = true;
  List<dynamic> _rows = const [];
  List<dynamic> _depots = const [];
  String _depotId = '';
  Map<String, dynamic>? _me;

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() => _loading = true);
    await Api.init();
    _me = await Api.ensureMe();
    final ds = await Api.depots();
    final ps = await Api.products(
      q: _qCtrl.text.trim(),
      depotId: _depotId,
      onlyInStock: _onlyInStock,
    );
    setState(() {
      _depots = ds;
      _rows = ps;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final perms = (_me?['permissions'] as Map?) ?? const {};
    bool hasEdit(Map? p) => p != null && p['edit'] == true;
    final canEditProducts = hasEdit(perms['*']) || hasEdit(perms['products']);

    return AdminScaffold(
      title: 'Produits',
      currentRoute: '/products',
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Wrap(
              spacing: 8,
              runSpacing: 8,
              crossAxisAlignment: WrapCrossAlignment.center,
              children: [
                SizedBox(
                  width: 280,
                  child: TextField(
                    controller: _qCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Rechercher (nom, SKU)',
                      border: OutlineInputBorder(),
                    ),
                    onChanged: (_) => _load(),
                  ),
                ),
                DropdownButton<String>(
                  value: _depotId.isEmpty ? null : _depotId,
                  hint: const Text('Tous les dépôts'),
                  items: [
                    const DropdownMenuItem<String>(
                      value: '',
                      child: Text('Tous les dépôts'),
                    ),
                    ..._depots.map((d) => DropdownMenuItem<String>(
                          value: '${d['id']}',
                          child: Text('${d['name']} (${d['code'] ?? ''})'),
                        )),
                  ],
                  onChanged: (v) {
                    setState(() => _depotId = v ?? '');
                    _load();
                  },
                ),
                FilterChip(
                  label: const Text('Seulement en stock'),
                  selected: _onlyInStock,
                  onSelected: (v) {
                    setState(() => _onlyInStock = v);
                    _load();
                  },
                ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  icon: const Icon(Icons.table_view_outlined),
                  label: const Text('Exporter CSV'),
                  onPressed: () async {
                    final uri = await Api.productsExportUrl(
                      q: _qCtrl.text.trim(),
                      depotId: _depotId,
                      onlyInStock: _onlyInStock,
                      format: 'csv',
                    );
                    await launchUrl(uri, mode: LaunchMode.externalApplication);
                  },
                ),
                OutlinedButton.icon(
                  icon: const Icon(Icons.picture_as_pdf_outlined),
                  label: const Text('Exporter PDF'),
                  onPressed: () async {
                    final uri = await Api.productsExportUrl(
                      q: _qCtrl.text.trim(),
                      depotId: _depotId,
                      onlyInStock: _onlyInStock,
                      format: 'pdf',
                    );
                    await launchUrl(uri, mode: LaunchMode.externalApplication);
                  },
                ),
              ],
            ),
            const SizedBox(height: 12),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _rows.isEmpty
                      ? const Center(child: Text('Aucun produit trouvé.'))
                      : GridView.builder(
                          gridDelegate:
                              const SliverGridDelegateWithFixedCrossAxisCount(
                            crossAxisCount: 2,
                            childAspectRatio: 3.2,
                            crossAxisSpacing: 8,
                            mainAxisSpacing: 8,
                          ),
                          itemCount: _rows.length,
                          itemBuilder: (ctx, i) {
                            final p = _rows[i] as Map<String, dynamic>;
                            final inactive = '${p['active'] ?? '1'}' == '0';
                            final stock = p.containsKey('stock_depot') &&
                                    p['stock_depot'] != null
                                ? p['stock_depot']
                                : p['stock_total'];
                            return Card(
                              elevation: 1,
                              child: Padding(
                                padding: const EdgeInsets.all(12),
                                child: Row(
                                  children: [
                                    const Icon(Icons.inventory_2_outlined,
                                        size: 40, color: Colors.black54),
                                    const SizedBox(width: 12),
                                    Expanded(
                                      child: Column(
                                        crossAxisAlignment:
                                            CrossAxisAlignment.start,
                                        mainAxisAlignment:
                                            MainAxisAlignment.center,
                                        children: [
                                          Row(
                                            children: [
                                              Expanded(
                                                child: Text(
                                                  '${p['name'] ?? ''}',
                                                  style: const TextStyle(
                                                      fontWeight:
                                                          FontWeight.w600),
                                                  overflow:
                                                      TextOverflow.ellipsis,
                                                ),
                                              ),
                                              if (inactive)
                                                Container(
                                                  padding: const EdgeInsets
                                                      .symmetric(
                                                      horizontal: 8,
                                                      vertical: 2),
                                                  decoration: BoxDecoration(
                                                    color: Colors.grey.shade300,
                                                    borderRadius:
                                                        BorderRadius.circular(
                                                            12),
                                                  ),
                                                  child: const Text('Inactif',
                                                      style: TextStyle(
                                                          fontSize: 12)),
                                                ),
                                            ],
                                          ),
                                          const SizedBox(height: 4),
                                          Text('SKU: ${p['sku'] ?? ''}',
                                              style: const TextStyle(
                                                  fontSize: 12,
                                                  color: Colors.black54)),
                                          const SizedBox(height: 4),
                                          Text('${p['unit_price'] ?? ''} FCFA',
                                              style: const TextStyle(
                                                  fontSize: 13)),
                                          if (stock != null)
                                            Padding(
                                              padding: const EdgeInsets.only(
                                                  top: 4.0),
                                              child: Text('Stock: $stock',
                                                  style: const TextStyle(
                                                      fontSize: 12)),
                                            ),
                                        ],
                                      ),
                                    ),
                                    const SizedBox(width: 8),
                                    IconButton(
                                      tooltip: 'Voir',
                                      onPressed: () {
                                        Navigator.of(context).pushNamed(
                                          '/product',
                                          arguments: p,
                                        );
                                      },
                                      icon:
                                          const Icon(Icons.visibility_outlined),
                                    ),
                                    if (canEditProducts)
                                      IconButton(
                                        tooltip: 'Modifier',
                                        onPressed: () async {
                                          final changed =
                                              await Navigator.of(context)
                                                  .pushNamed('/product_form',
                                                      arguments: p);
                                          if (changed == true && mounted)
                                            _load();
                                        },
                                        icon: const Icon(Icons.edit_outlined),
                                      ),
                                  ],
                                ),
                              ),
                            );
                          },
                        ),
            ),
          ],
        ),
      ),
      floatingActionButton: canEditProducts
          ? FloatingActionButton.extended(
              onPressed: () async {
                final changed =
                    await Navigator.of(context).pushNamed('/product_form');
                if (changed == true) _load();
              },
              icon: const Icon(Icons.add),
              label: const Text('Nouveau produit'),
            )
          : null,
    );
  }

  @override
  void dispose() {
    _qCtrl.dispose();
    super.dispose();
  }
}
