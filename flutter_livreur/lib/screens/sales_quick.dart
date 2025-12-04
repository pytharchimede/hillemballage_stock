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
    final depotId = int.tryParse(round!['depot_id'].toString()) ?? 0;

    // Statistiques (qty_sold)
    final stats = await Api.roundStats(round!['id'] as int);

    // Produits attribués
    final assigned = (round!['items'] as List<dynamic>? ?? []);

    // Produits du dépôt
    final depotProducts = await Api.depotProducts(depotId);

    // Map rapide des stats
    final statMap = <int, Map<String, dynamic>>{};
    for (final it in (stats?['items'] as List<dynamic>? ?? [])) {
      statMap[(it['product_id'] as int)] = it as Map<String, dynamic>;
    }

    // Construction liste affichable
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

      final remaining = qtyAssigned - qtySold;

      if (remaining > 0) {
        list.add({
          'id': pid,
          'name': (full['name'] ?? it['name'] ?? 'Produit #$pid').toString(),
          'unit_price': int.tryParse(
                (full['unit_price'] ?? it['unit_price'] ?? '0').toString(),
              ) ??
              0,
          'stock_depot': remaining,
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
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Stock insuffisant')),
      );
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
    final roundId = int.tryParse(round!['id'].toString()) ?? 0;

    if (selectedClient == null) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Client requis.')));
      return;
    }

    try {
      await Api.createSale(
        depotId: depotId,
        clientId: int.tryParse(selectedClient!['id'].toString()) ?? 0,
        sellerRoundId: roundId,
        items: cart
            .map((it) => {
                  'product_id': it['product_id'],
                  'quantity': it['quantity'],
                  'unit_price': it['unit_price'],
                })
            .toList(),
        paymentAmount: paid,
      );

      if (!mounted) return;

      showDialog(
        context: context,
        builder: (_) => AlertDialog(
          shape:
              RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          title: const Text('Vente enregistrée'),
          content: const Text('La vente a été validée avec succès.'),
          actions: [
            TextButton(
                onPressed: () => Navigator.pop(context),
                child: const Text('OK')),
          ],
        ),
      );

      setState(() {
        cart.clear();
        paid = 0;
        selectedClient = null;
      });

      await _load();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text('Erreur : $e')));
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
      backgroundColor: Colors.white,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(18)),
      ),
      builder: (ctx) {
        return Padding(
          padding: EdgeInsets.only(
            bottom: MediaQuery.of(ctx).viewInsets.bottom,
            left: 16,
            right: 16,
            top: 16,
          ),
          child: StatefulBuilder(
            builder: (ctx, setSt) => SingleChildScrollView(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  // Header
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Text('Sélectionner un client',
                          style: TextStyle(
                              fontWeight: FontWeight.bold, fontSize: 16)),
                      IconButton(
                          onPressed: () => Navigator.pop(ctx),
                          icon: const Icon(Icons.close))
                    ],
                  ),

                  const SizedBox(height: 12),

                  // Recherche
                  TextField(
                    decoration: InputDecoration(
                      hintText: "Rechercher par nom ou téléphone",
                      filled: true,
                      fillColor: Colors.grey.shade100,
                      prefixIcon: const Icon(Icons.search),
                      border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(12),
                          borderSide: BorderSide.none),
                    ),
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

                  const SizedBox(height: 12),

                  // Liste clients
                  Container(
                    constraints: const BoxConstraints(maxHeight: 260),
                    child: ListView.builder(
                      itemCount: shown.length,
                      itemBuilder: (c, i) {
                        final cl = shown[i] as Map<String, dynamic>;
                        return Card(
                          margin: const EdgeInsets.symmetric(vertical: 4),
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10)),
                          child: ListTile(
                            title: Text(cl['name'] ?? "Client"),
                            subtitle: Text(cl['phone'] ?? ""),
                            trailing: const Icon(Icons.chevron_right),
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
                          ),
                        );
                      },
                    ),
                  ),

                  const SizedBox(height: 12),
                  const Divider(),

                  // Nouveau client
                  const Text('Nouveau client',
                      style:
                          TextStyle(fontWeight: FontWeight.bold, fontSize: 15)),
                  const SizedBox(height: 8),

                  _inputField("Nom", nameCtrl),
                  const SizedBox(height: 8),
                  _inputField("Téléphone", phoneCtrl),
                  const SizedBox(height: 8),
                  _inputField("Adresse", addrCtrl),
                  const SizedBox(height: 16),

                  SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      style: ElevatedButton.styleFrom(
                          shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(12)),
                          padding: const EdgeInsets.symmetric(vertical: 14)),
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

                  const SizedBox(height: 30),
                ],
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _inputField(String label, TextEditingController ctrl) {
    return TextField(
      controller: ctrl,
      decoration: InputDecoration(
        labelText: label,
        filled: true,
        fillColor: Colors.grey.shade100,
        border: OutlineInputBorder(
            borderRadius: BorderRadius.circular(12),
            borderSide: BorderSide.none),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final fmt = NumberFormat.decimalPattern('fr_FR');

    return AppScaffold(
      title: 'Vente rapide',
      currentRoute: '/sales_quick',
      body: Stack(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(12, 12, 12, 80),
            child: Column(
              children: [
                _clientBanner(fmt),
                const SizedBox(height: 10),
                _productGrid(fmt),
                const SizedBox(height: 10),
                _cartSection(fmt),
              ],
            ),
          ),

          // BOUTON FLOTTANT — TOTAL + VALIDER
          Positioned(
            bottom: 12,
            left: 12,
            right: 12,
            child: ElevatedButton(
              style: ElevatedButton.styleFrom(
                padding: const EdgeInsets.symmetric(vertical: 16),
                backgroundColor: cart.isEmpty ? Colors.grey : Colors.blue,
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12)),
              ),
              onPressed: cart.isEmpty ? null : _submit,
              child: Text(
                'Valider • ${fmt.format(_total())} FCFA',
                style:
                    const TextStyle(fontWeight: FontWeight.bold, fontSize: 17),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _clientBanner(NumberFormat fmt) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 1,
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Row(
          children: [
            Expanded(
              child: Text(
                selectedClient == null
                    ? 'Aucun client sélectionné'
                    : '${selectedClient!['name']}'
                        '${selectedClient!['phone'] != null ? " • ${selectedClient!['phone']}" : ""}'
                        '${clientBalance != null ? " • Solde: ${fmt.format(clientBalance)} FCFA" : ""}',
                style: TextStyle(
                  fontWeight: selectedClient == null
                      ? FontWeight.normal
                      : FontWeight.w600,
                  color: Colors.black87,
                ),
              ),
            ),
            ElevatedButton(
              onPressed: _openClientPicker,
              style: ElevatedButton.styleFrom(
                shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10)),
              ),
              child: const Text("Client"),
            )
          ],
        ),
      ),
    );
  }

  Widget _productGrid(NumberFormat fmt) {
    if (products.isEmpty) {
      return const Expanded(
          child: Center(child: Text("Aucun produit attribué.")));
    }

    return Expanded(
      child: GridView.extent(
        maxCrossAxisExtent: 170,
        mainAxisSpacing: 8,
        crossAxisSpacing: 8,
        children: [
          for (final p in products)
            Card(
              shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12)),
              elevation: 1,
              child: InkWell(
                borderRadius: BorderRadius.circular(12),
                onTap: () => _addToCart(p),
                child: Padding(
                  padding: const EdgeInsets.all(10),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(p['name'],
                          style: const TextStyle(
                              fontWeight: FontWeight.w600, fontSize: 15)),
                      const SizedBox(height: 6),
                      Text('PU: ${fmt.format(p['unit_price'])} FCFA',
                          style: const TextStyle(
                              fontSize: 13, color: Colors.black54)),
                      Text('Stock: ${p['stock_depot']}',
                          style: const TextStyle(
                              fontSize: 13, color: Colors.black54)),
                    ],
                  ),
                ),
              ),
            )
        ],
      ),
    );
  }

  Widget _cartSection(NumberFormat fmt) {
    return Card(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      elevation: 1,
      child: Padding(
        padding: const EdgeInsets.all(10),
        child: Column(
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text("Panier",
                    style:
                        TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
                Text(
                  '${fmt.format(_total())} FCFA',
                  style: const TextStyle(
                      fontWeight: FontWeight.bold, fontSize: 16),
                )
              ],
            ),
            const SizedBox(height: 8),
            if (cart.isEmpty) const Text("Aucun article dans le panier."),
            if (cart.isNotEmpty)
              Column(
                children: [
                  for (int i = 0; i < cart.length; i++)
                    ListTile(
                      dense: true,
                      title: Text(cart[i]['name']),
                      subtitle:
                          Text("PU: ${fmt.format(cart[i]['unit_price'])} FCFA"),
                      trailing: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          IconButton(
                            icon: const Icon(Icons.remove_circle_outline),
                            onPressed: () {
                              setState(() {
                                cart[i]['quantity']--;
                                if (cart[i]['quantity'] <= 0) {
                                  cart.removeAt(i);
                                }
                              });
                            },
                          ),
                          Text("${cart[i]['quantity']}"),
                          IconButton(
                            icon: const Icon(Icons.add_circle_outline),
                            onPressed: () {
                              setState(() {
                                cart[i]['quantity']++;
                              });
                            },
                          ),
                        ],
                      ),
                    ),
                ],
              ),
            const SizedBox(height: 8),
            TextField(
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: "Montant payé"),
              onChanged: (v) {
                paid = int.tryParse(v) ?? 0;
              },
            ),
          ],
        ),
      ),
    );
  }
}
