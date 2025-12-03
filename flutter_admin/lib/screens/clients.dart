import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';
import '../api.dart';

class ClientsScreen extends StatefulWidget {
  const ClientsScreen({super.key});

  @override
  State<ClientsScreen> createState() => _ClientsScreenState();
}

class _ClientsScreenState extends State<ClientsScreen> {
  final _qCtrl = TextEditingController();
  bool _loading = true;
  List<dynamic> _rows = const [];
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
    final cs = await Api.clients(q: _qCtrl.text.trim());
    setState(() {
      _rows = cs;
      _loading = false;
    });
  }

  @override
  Widget build(BuildContext context) {
    final perms = (_me?['permissions'] as Map?) ?? const {};
    final canEditClients =
        (perms['*']?['edit'] == true) || (perms['clients']?['edit'] == true);

    return AdminScaffold(
      title: 'Clients',
      currentRoute: '/clients',
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 320,
              child: TextField(
                controller: _qCtrl,
                decoration: const InputDecoration(
                  labelText: 'Rechercher (nom, téléphone)',
                  border: OutlineInputBorder(),
                ),
                onChanged: (_) => _load(),
              ),
            ),
            const SizedBox(height: 12),
            Expanded(
              child: _loading
                  ? const Center(child: CircularProgressIndicator())
                  : _rows.isEmpty
                      ? const Center(child: Text('Aucun client trouvé.'))
                      : ListView.separated(
                          itemCount: _rows.length,
                          separatorBuilder: (_, __) =>
                              const SizedBox(height: 8),
                          itemBuilder: (ctx, i) {
                            final c = _rows[i] as Map<String, dynamic>;
                            return Card(
                              elevation: 1,
                              child: ListTile(
                                leading: const Icon(Icons.person_outline),
                                title: Text('${c['name'] ?? ''}'),
                                subtitle: Text('${c['phone'] ?? ''}'),
                                trailing: Column(
                                  mainAxisAlignment: MainAxisAlignment.center,
                                  crossAxisAlignment: CrossAxisAlignment.end,
                                  children: [
                                    if (c.containsKey('balance'))
                                      Text('Solde: ${c['balance']}',
                                          style: const TextStyle(fontSize: 12)),
                                    if (c.containsKey('credit_limit'))
                                      Text('Limite: ${c['credit_limit']}',
                                          style: const TextStyle(fontSize: 12)),
                                  ],
                                ),
                                onTap: canEditClients
                                    ? () async {
                                        final changed =
                                            await Navigator.of(context)
                                                .pushNamed('/client_form',
                                                    arguments: c);
                                        if (changed == true && mounted) _load();
                                      }
                                    : null,
                              ),
                            );
                          },
                        ),
            ),
          ],
        ),
      ),
      floatingActionButton: canEditClients
          ? FloatingActionButton.extended(
              onPressed: () async {
                final changed =
                    await Navigator.of(context).pushNamed('/client_form');
                if (changed == true && mounted) _load();
              },
              icon: const Icon(Icons.add),
              label: const Text('Nouveau client'),
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
