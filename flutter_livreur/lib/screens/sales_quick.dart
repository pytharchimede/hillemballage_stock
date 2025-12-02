import 'package:flutter/material.dart';
import '../api.dart';
import 'package:intl/intl.dart';
import '../widgets/app_scaffold.dart';

class SalesQuickScreen extends StatefulWidget {
  const SalesQuickScreen({super.key});

  @override
  State<SalesQuickScreen> createState() => _SalesQuickScreenState();
}

class _SalesQuickScreenState extends State<SalesQuickScreen> {
  Map<String, dynamic>? round;
  List<dynamic> products = [];
  final List<Map<String, dynamic>> cart = [];
  int paid = 0;
  Map<String, dynamic>? selectedClient;
  int? clientBalance;
  bool _authChecked = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    round ??=
        (ModalRoute.of(context)?.settings.arguments as Map<String, dynamic>?);
    _ensureAuthThenLoad();
  }

  Future<void> _ensureAuthThenLoad() async {
    if (_authChecked) return;
    _authChecked = true;
    final me = await Api.me();
    if (me == null) {
      if (!mounted) return;
      Navigator.of(context).pushNamedAndRemoveUntil('/login', (r) => false);
      return;
    }
    await _load();
  }

  Future<void> _load() async {
    if (round == null) return;
    final depotId = round!['depot_id'] as int? ?? 0;
    final stats = await Api.roundStats(round!['id'] as int);
    final assigned = (round!['items'] as List<dynamic>? ?? []);
    final depotProducts = await Api.depotProducts(depotId);
    final statMap = <int, Map<String, dynamic>>{};
    for (final it in (stats?['items'] as List<dynamic>? ?? [])) {
      statMap[(it['product_id'] as int)] = it as Map<String, dynamic>;
    }
    final list = <Map<String, dynamic>>[];
    for (final it in assigned) {
      final pid = int.tryParse(it['product_id'].toString()) ?? 0;
      final full = depotProducts.firstWhere(
        (p) => int.tryParse(p['id'].toString()) == pid,
        orElse: () => {},
      );
      final st = statMap[pid] ?? {};
      final qtyAssigned = int.tryParse(it['qty_assigned'].toString()) ?? 0;
      final qtySold = int.tryParse((st['qty_sold'] ?? '0').toString()) ?? 0;
      final stockDepot = qtyAssigned - qtySold;
      if (stockDepot > 0) {
        list.add({
          'id': pid,
          'name': (full['name'] ?? it['name'] ?? 'Produit #$pid').toString(),
          'unit_price': int.tryParse(
                (full['unit_price'] ?? it['unit_price'] ?? '0').toString(),
              ) ??
              0,
          'stock_depot': stockDepot,
        });
      }
    }
    setState(() {
      products = list;
    });
  }

  int _total() => cart.fold(
        0,
        (a, it) => a + (it['unit_price'] as int) * (it['quantity'] as int),
      );

  void _addToCart(Map<String, dynamic> p) {
    final idx = cart.indexWhere((e) => e['product_id'] == p['id']);
    final inCartQty = idx >= 0 ? cart[idx]['quantity'] as int : 0;
    final nextQty = inCartQty + 1;
    if (nextQty > (p['stock_depot'] as int)) {
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Stock insuffisant')));
      return;
    }
    if (idx >= 0) {
      cart[idx]['quantity'] = nextQty;
    } else {
      cart.add({
        'product_id': p['id'],
        'name': p['name'],
        'unit_price': p['unit_price'],
        'quantity': 1,
      });
    }
    setState(() {});
  }

  Future<void> _submit() async {
    final depotId = round!['depot_id'] as int;
    final roundId = round!['id'] as int;
    if (selectedClient == null || selectedClient!['id'] == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Veuillez sélectionner un client')),
      );
      return;
    }
    try {
      await Api.createSale(
        depotId: depotId,
        clientId: int.tryParse(selectedClient!['id'].toString()) ?? 0,
        sellerRoundId: roundId,
        items: cart
            .map(
              (it) => {
                'product_id': it['product_id'],
                'quantity': it['quantity'],
                'unit_price': it['unit_price'],
              },
            )
            .toList(),
        paymentAmount: paid,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(
        context,
      ).showSnackBar(const SnackBar(content: Text('Vente enregistrée')));
      setState(() {
        cart.clear();
        paid = 0;
        selectedClient = null;
      });
      await _load();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Erreur: $e')));
    }
  }

  Future<void> _openClientPicker() async {
    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final addrCtrl = TextEditingController();
    List<dynamic> all = await Api.clients();
    List<dynamic> shown = List<dynamic>.from(all);
    if (!mounted) return;
    await showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      builder: (ctx) {
        return Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom,
            left: 12,
            right: 12,
            top: 12,
          ),
          child: StatefulBuilder(
            builder: (ctx, setSt) => SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text('Sélectionner un client',
                          style: TextStyle(fontWeight: FontWeight.w600)),
                      IconButton(
                          onPressed: () => Navigator.pop(ctx),
                          icon: const Icon(Icons.close))
                    ],
                  ),
                  const SizedBox(height: 8),
                  TextField(
                    decoration: const InputDecoration(
                        labelText: 'Rechercher (nom/téléphone)'),
                    onChanged: (q) {
                      setSt(() {
                        if (q.trim().isEmpty) {
                          shown = List<dynamic>.from(all);
                        } else {
                          final qq = q.toLowerCase();
                          shown = all.where((c) {
                            final n =
                                (c['name'] ?? '').toString().toLowerCase();
                            final p =
                                (c['phone'] ?? '').toString().toLowerCase();
                            return n.contains(qq) || p.contains(qq);
                          }).toList();
                        }
                      });
                    },
                  ),
                  const SizedBox(height: 8),
                  Container(
                    constraints: const BoxConstraints(maxHeight: 260),
                    child: ListView.builder(
                      itemCount: shown.length,
                      itemBuilder: (c, i) {
                        final cl = shown[i] as Map<String, dynamic>;
                        return ListTile(
                          dense: true,
                          title: Text(cl['name']?.toString() ?? 'Client'),
                          subtitle: Text((cl['phone'] ?? '').toString()),
                          onTap: () async {
                            setState(() {
                              selectedClient = cl;
                              clientBalance = null;
                            });
                            Navigator.pop(ctx);
                            final id = int.tryParse((cl['id']).toString());
                            if (id != null) {
                              final full = await Api.getClient(id);
                              if (!mounted) return;
                              setState(() {
                                clientBalance = int.tryParse(
                                    (full?['balance'] ?? '0').toString());
                              });
                            }
                          },
                        );
                      },
                    ),
                  ),
                  const Divider(height: 24),
                  const Text('Nouveau client',
                      style: TextStyle(fontWeight: FontWeight.w600)),
                  const SizedBox(height: 6),
                  TextField(
                      controller: nameCtrl,
                      decoration: const InputDecoration(labelText: 'Nom')),
                  const SizedBox(height: 6),
                  TextField(
                      controller: phoneCtrl,
                      decoration:
                          const InputDecoration(labelText: 'Téléphone')),
                  const SizedBox(height: 6),
                  TextField(
                      controller: addrCtrl,
                      decoration: const InputDecoration(labelText: 'Adresse')),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: () async {
                        final n = nameCtrl.text.trim();
                        if (n.isEmpty) {
                          ScaffoldMessenger.of(ctx).showSnackBar(
                            const SnackBar(content: Text('Nom requis')),
                          );
                          return;
                        }
                        final created = await Api.createClient(
                          name: n,
                          phone: phoneCtrl.text.trim(),
                          address: addrCtrl.text.trim(),
                        );
                        if (created != null) {
                          setState(() {
                            selectedClient = created;
                            clientBalance = null;
                          });
                          Navigator.pop(ctx);
                          final id = int.tryParse((created['id']).toString());
                          if (id != null) {
                            final full = await Api.getClient(id);
                            if (!mounted) return;
                            setState(() {
                              clientBalance = int.tryParse(
                                  (full?['balance'] ?? '0').toString());
                            });
                          }
                        } else {
                          if (!mounted) return;
                          ScaffoldMessenger.of(ctx).showSnackBar(
                            const SnackBar(
                                content: Text('Échec création client')),
                          );
                        }
                      },
                      child: const Text('Créer et sélectionner'),
                    ),
                  ),
                  const SizedBox(height: 8),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  @override
  Widget build(BuildContext context) {
    final fmt = NumberFormat.decimalPattern('fr_FR');
    return AppScaffold(
      title: 'Vente rapide',
      currentRoute: '/sales_quick',
      body: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          children: [
            // Bandeau sélection client
            Card(
              child: Padding(
                padding: const EdgeInsets.all(8.0),
                child: Row(
                  children: [
                    Expanded(
                      child: Text(
                        selectedClient == null
                            ? 'Aucun client sélectionné'
                            : '${selectedClient!['name'] ?? 'Client'}${selectedClient!['phone'] != null && (selectedClient!['phone'].toString().isNotEmpty) ? ' • ${selectedClient!['phone']}' : ''}${clientBalance != null ? ' • Solde: ${fmt.format(clientBalance)} FCFA' : ''}',
                        style: TextStyle(
                            color: selectedClient == null
                                ? Colors.black54
                                : Colors.black87,
                            fontWeight: selectedClient == null
                                ? FontWeight.normal
                                : FontWeight.w600),
                      ),
                    ),
                    ElevatedButton(
                      onPressed: _openClientPicker,
                      child: const Text('Sélectionner / Créer'),
                    )
                  ],
                ),
              ),
            ),
            if (products.isEmpty) const Text('Aucun produit attribué'),
            if (products.isNotEmpty)
              Expanded(
                child: GridView.extent(
                  maxCrossAxisExtent: 180,
                  mainAxisSpacing: 8,
                  crossAxisSpacing: 8,
                  children: [
                    for (final p in products)
                      Card(
                        child: InkWell(
                          onTap: () => _addToCart(p),
                          child: Padding(
                            padding: const EdgeInsets.all(8),
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  p['name'],
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  'PU: ${fmt.format(p['unit_price'])} FCFA',
                                  style: const TextStyle(color: Colors.black54),
                                ),
                                Text(
                                  'Stock: ${p['stock_depot']}',
                                  style: const TextStyle(color: Colors.black54),
                                ),
                              ],
                            ),
                          ),
                        ),
                      ),
                  ],
                ),
              ),
            const SizedBox(height: 8),
            Card(
              child: Padding(
                padding: const EdgeInsets.all(8),
                child: Column(
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text('Panier'),
                        Text(
                          'Total: ${fmt.format(_total())} FCFA',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    const SizedBox(height: 6),
                    if (cart.isEmpty) const Text('Aucun article'),
                    if (cart.isNotEmpty)
                      Column(
                        children: [
                          for (int i = 0; i < cart.length; i++)
                            ListTile(
                              title: Text(cart[i]['name'].toString()),
                              subtitle: Text(
                                'PU: ${fmt.format(cart[i]['unit_price'])} FCFA',
                              ),
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  IconButton(
                                    onPressed: () {
                                      setState(() {
                                        cart[i]['quantity'] =
                                            (cart[i]['quantity'] as int) - 1;
                                        if ((cart[i]['quantity'] as int) <= 0) {
                                          cart.removeAt(i);
                                        }
                                      });
                                    },
                                    icon: const Icon(
                                      Icons.remove_circle_outline,
                                    ),
                                  ),
                                  Text('${cart[i]['quantity']}'),
                                  IconButton(
                                    onPressed: () {
                                      setState(() {
                                        cart[i]['quantity'] =
                                            (cart[i]['quantity'] as int) + 1;
                                      });
                                    },
                                    icon: const Icon(Icons.add_circle_outline),
                                  ),
                                ],
                              ),
                            ),
                        ],
                      ),
                    const SizedBox(height: 8),
                    TextField(
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'Montant payé',
                      ),
                      onChanged: (v) {
                        paid = int.tryParse(v) ?? 0;
                      },
                    ),
                    const SizedBox(height: 8),
                    SizedBox(
                      width: double.infinity,
                      child: ElevatedButton(
                        onPressed: cart.isEmpty ? null : _submit,
                        child: const Text('Valider la vente'),
                      ),
                    ),
                  ],
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
