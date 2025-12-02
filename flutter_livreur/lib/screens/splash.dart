import 'package:flutter/material.dart';
import '../api.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    _bootstrap();
  }

  Future<void> _bootstrap() async {
    // Option: petite attente pour laisser le temps d'afficher le splash
    await Future<void>.delayed(const Duration(milliseconds: 250));
    try {
      final me = await Api.me();
      if (!mounted) return;
      if (me != null) {
        Navigator.of(context)
            .pushNamedAndRemoveUntil('/dashboard', (r) => false);
      } else {
        Navigator.of(context).pushNamedAndRemoveUntil('/login', (r) => false);
      }
    } catch (_) {
      if (!mounted) return;
      Navigator.of(context).pushNamedAndRemoveUntil('/login', (r) => false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: Center(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            SizedBox(height: 12),
            CircularProgressIndicator(),
            SizedBox(height: 12),
            Text('Chargement...'),
          ],
        ),
      ),
    );
  }
}
