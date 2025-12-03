import 'package:flutter/material.dart';
import '../widgets/admin_scaffold.dart';
import '../api.dart';

class ClientFormScreen extends StatefulWidget {
  const ClientFormScreen({super.key});

  @override
  State<ClientFormScreen> createState() => _ClientFormScreenState();
}

class _ClientFormScreenState extends State<ClientFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _addrCtrl = TextEditingController();
  final _limitCtrl = TextEditingController();
  int? _id;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final args = ModalRoute.of(context)?.settings.arguments;
    if (args is Map<String, dynamic> && _id == null) {
      _id = (args['id'] as num?)?.toInt();
      _nameCtrl.text = (args['name'] ?? '') as String;
      _phoneCtrl.text = (args['phone'] ?? '') as String;
      _addrCtrl.text = (args['address'] ?? '') as String;
      final cl = args['credit_limit'];
      if (cl != null) _limitCtrl.text = '$cl';
      setState(() {});
    }
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final name = _nameCtrl.text.trim();
    final phone =
        _phoneCtrl.text.trim().isEmpty ? null : _phoneCtrl.text.trim();
    final addr = _addrCtrl.text.trim().isEmpty ? null : _addrCtrl.text.trim();
    final limit = _limitCtrl.text.trim().isEmpty
        ? null
        : num.tryParse(_limitCtrl.text.trim());
    if (_id == null) {
      final out = await Api.createClient(
        name: name,
        phone: phone,
        address: addr,
        creditLimit: limit,
      );
      if (!mounted) return;
      if (out != null) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Client créé')));
        Navigator.of(context).pop(true);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Échec de la création')));
      }
    } else {
      final out = await Api.updateClient(
        id: _id!,
        name: name,
        phone: phone,
        address: addr,
        creditLimit: limit,
      );
      if (!mounted) return;
      if (out != null) {
        ScaffoldMessenger.of(context)
            .showSnackBar(const SnackBar(content: Text('Client enregistré')));
        Navigator.of(context).pop(true);
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Échec de la mise à jour')));
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return AdminScaffold(
      title: _id == null ? 'Nouveau client' : 'Modifier client',
      currentRoute: '/clients',
      body: Padding(
        padding: const EdgeInsets.all(12.0),
        child: Form(
          key: _formKey,
          child: ListView(
            children: [
              TextFormField(
                controller: _nameCtrl,
                decoration: const InputDecoration(
                  labelText: 'Nom',
                  border: OutlineInputBorder(),
                ),
                validator: (v) =>
                    (v == null || v.trim().isEmpty) ? 'Nom requis' : null,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _phoneCtrl,
                keyboardType: TextInputType.phone,
                decoration: const InputDecoration(
                  labelText: 'Téléphone',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _addrCtrl,
                decoration: const InputDecoration(
                  labelText: 'Adresse',
                  border: OutlineInputBorder(),
                ),
                maxLines: 2,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _limitCtrl,
                keyboardType: TextInputType.number,
                decoration: const InputDecoration(
                  labelText: 'Limite de crédit',
                  border: OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 16),
              Row(
                children: [
                  ElevatedButton.icon(
                    onPressed: _save,
                    icon: const Icon(Icons.save_outlined),
                    label: const Text('Enregistrer'),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    onPressed: () => Navigator.of(context).pop(),
                    child: const Text('Annuler'),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _addrCtrl.dispose();
    _limitCtrl.dispose();
    super.dispose();
  }
}
