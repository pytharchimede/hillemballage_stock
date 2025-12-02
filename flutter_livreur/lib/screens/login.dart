import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../api.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _loading = false;
  String? _error;
  Map<String, dynamic>? _lastDebug;
  bool _debugOpen = true;

  Future<void> _submit() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final j = await Api.login(_email.text.trim(), _password.text);
      setState(() {
        _lastDebug = j;
      });
      if (j != null && j['token'] != null) {
        if (!mounted) return;
        Navigator.of(context).pushReplacementNamed('/dashboard');
      } else {
        final msg = (j != null && j['error'] is String)
            ? (j['error'] as String)
            : 'Identifiants invalides';
        setState(() {
          _error = msg;
        });
      }
    } catch (e) {
      setState(() {
        _error = 'Erreur de connexion';
      });
    } finally {
      if (mounted) {
        setState(() {
          _loading = false;
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Connexion livreur'),
        actions: [
          IconButton(
            tooltip: 'Réglages API',
            icon: const Icon(Icons.settings),
            onPressed: () => Navigator.of(context).pushNamed('/settings'),
          ),
        ],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: _email,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'Email'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: _password,
                  decoration: const InputDecoration(labelText: 'Mot de passe'),
                  obscureText: true,
                ),
                const SizedBox(height: 12),
                if (_error != null)
                  Text(_error!, style: const TextStyle(color: Colors.red)),
                const SizedBox(height: 12),
                SizedBox(
                  width: double.infinity,
                  child: ElevatedButton(
                    onPressed: _loading ? null : _submit,
                    child: _loading
                        ? const CircularProgressIndicator()
                        : const Text('Se connecter'),
                  ),
                ),
                const SizedBox(height: 6),
                Align(
                  alignment: Alignment.centerLeft,
                  child: Text(
                    'API: ${Api.base}',
                    style: const TextStyle(fontSize: 12, color: Colors.black54),
                  ),
                ),
                const SizedBox(height: 12),
                if (_lastDebug != null)
                  Card(
                    child: Padding(
                      padding: const EdgeInsets.all(8.0),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceBetween,
                            children: [
                              const Text('Debug API',
                                  style:
                                      TextStyle(fontWeight: FontWeight.w600)),
                              Row(children: [
                                Text('Status: ${_lastDebug!['status'] ?? '-'}',
                                    style:
                                        const TextStyle(color: Colors.black54)),
                                const SizedBox(width: 8),
                                IconButton(
                                  tooltip: 'Copier',
                                  icon: const Icon(Icons.copy, size: 18),
                                  onPressed: () {
                                    final j = const JsonEncoder.withIndent('  ')
                                        .convert(_lastDebug);
                                    Clipboard.setData(ClipboardData(text: j));
                                  },
                                ),
                                IconButton(
                                  tooltip: _debugOpen ? 'Masquer' : 'Afficher',
                                  icon: Icon(
                                      _debugOpen
                                          ? Icons.expand_less
                                          : Icons.expand_more,
                                      size: 18),
                                  onPressed: () {
                                    setState(() {
                                      _debugOpen = !_debugOpen;
                                    });
                                  },
                                ),
                              ])
                            ],
                          ),
                          if (_debugOpen) ...[
                            const SizedBox(height: 6),
                            Container(
                              constraints: const BoxConstraints(maxHeight: 220),
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                color: const Color(0xFFF5F5F5),
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: SingleChildScrollView(
                                child: SelectableText(
                                  const JsonEncoder.withIndent('  ')
                                      .convert(_lastDebug),
                                  style: const TextStyle(
                                      fontFamily: 'monospace', fontSize: 12),
                                ),
                              ),
                            ),
                          ]
                        ],
                      ),
                    ),
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
    _email.dispose();
    _password.dispose();
    super.dispose();
  }
}
