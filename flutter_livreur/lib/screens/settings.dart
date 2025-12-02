import 'package:flutter/material.dart';
import '../api.dart';

class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  late final TextEditingController _baseCtrl;
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _baseCtrl = TextEditingController(text: Api.base);
  }

  Future<void> _save() async {
    final v = _baseCtrl.text.trim();
    if (v.isEmpty) {
      ScaffoldMessenger.of(context)
          .showSnackBar(const SnackBar(content: Text('Base API requise')));
      return;
    }
    setState(() => _saving = true);
    try {
      await Api.setBase(v);
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Base API mise à jour')),
      );
      setState(() {});
    } finally {
      if (mounted) {
        setState(() => _saving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Réglages API')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 520),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  'URL de base de l‘API',
                  style: TextStyle(fontWeight: FontWeight.w600),
                ),
                const SizedBox(height: 8),
                TextField(
                  controller: _baseCtrl,
                  decoration: const InputDecoration(
                    hintText: 'Ex: https://app.hillemballage.ci/public',
                    labelText: 'Base API',
                  ),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    ElevatedButton(
                      onPressed: _saving ? null : _save,
                      child: _saving
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Enregistrer'),
                    ),
                    const SizedBox(width: 12),
                    TextButton(
                      onPressed: _saving
                          ? null
                          : () {
                              setState(() {
                                _baseCtrl.text =
                                    'https://app.hillemballage.ci/public';
                              });
                            },
                      child: const Text('Remettre par défaut'),
                    ),
                  ],
                ),
                const SizedBox(height: 24),
                Text(
                  'Actuelle: ${Api.base}',
                  style: const TextStyle(color: Colors.black54),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  @override
  void dispose() {
    _baseCtrl.dispose();
    super.dispose();
  }
}
