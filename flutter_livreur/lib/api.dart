import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

class Api {
  // Adapter l’URL selon environnement (prod/local)
  static String base = 'https://app.hillemballage.ci/public';

  // Charger la base sauvegardée au démarrage
  static Future<void> init() async {
    final sp = await SharedPreferences.getInstance();
    final saved = sp.getString('api_base');
    if (saved != null && saved.isNotEmpty) {
      base = saved;
    }
  }

  static Future<void> setBase(String b) async {
    // Retirer un slash de fin proprement
    base = b.replaceAll(RegExp(r'\/$'), '');
    final sp = await SharedPreferences.getInstance();
    await sp.setString('api_base', base);
  }

  static Future<String?> getToken() async {
    final sp = await SharedPreferences.getInstance();
    return sp.getString('api_token');
  }

  static Future<void> setToken(String t) async {
    final sp = await SharedPreferences.getInstance();
    await sp.setString('api_token', t);
  }

  static Future<Map<String, String>> _headers({bool json = true}) async {
    final tok = await getToken();
    final h = <String, String>{};
    if (json) h['Content-Type'] = 'application/json';
    if (tok != null && tok.isNotEmpty) h['Authorization'] = 'Bearer $tok';
    return h;
  }

  static Future<Map<String, dynamic>?> login(
    String email,
    String password,
  ) async {
    final url = Uri.parse('$base/api/v1/auth/login');
    // Pour login, ne pas envoyer d'Authorization existant
    final reqHeaders = <String, String>{
      'Accept': 'application/json',
    };
    // Envoyer en x-www-form-urlencoded pour éviter un préflight CORS
    final payloadForm = {
      'email': email,
      'password': password,
    };
    try {
      final res = await http.post(
        url,
        headers: reqHeaders,
        body: payloadForm,
      );
      Map<String, dynamic>? parsed;
      try {
        parsed = jsonDecode(res.body) as Map<String, dynamic>?;
      } catch (_) {}
      String? token;
      if (parsed != null) {
        token = parsed['token'] as String?;
        token ??= parsed['access_token'] as String?;
        final d = parsed['data'];
        if (token == null && d is Map<String, dynamic>) {
          token = d['token'] as String?;
          token ??= d['access_token'] as String?;
        }
      }
      if (res.statusCode >= 200 && res.statusCode < 300 && token != null) {
        await setToken(token);
      }
      return <String, dynamic>{
        'ok': (res.statusCode >= 200 && res.statusCode < 300) && token != null,
        'status': res.statusCode,
        'url': url.toString(),
        'requestHeaders': reqHeaders,
        'requestBody': {'email': email, 'password': '***'},
        'responseHeaders': res.headers,
        'rawBody': res.body,
        'json': parsed,
        'token': token,
        'error': parsed != null ? parsed['error'] : null,
      };
    } catch (e) {
      return <String, dynamic>{
        'ok': false,
        'status': 0,
        'url': url.toString(),
        'requestHeaders': reqHeaders,
        'requestBody': {'email': email, 'password': '***'},
        'responseHeaders': const <String, String>{},
        'rawBody': null,
        'json': null,
        'token': null,
        'error': e.toString(),
        'networkError': true,
      };
    }
  }

  static Future<Map<String, dynamic>?> me() async {
    final url = Uri.parse('$base/api/v1/auth/me');
    final res = await http.get(url, headers: await _headers(json: false));
    if (res.statusCode == 200) return jsonDecode(res.body);
    return null;
  }

  // Liste des clients (scopée par rôle côté backend). Option q (si supportée) sinon filtrer côté app.
  static Future<List<dynamic>> clients({String? q}) async {
    final uri = Uri.parse(
      q != null && q.isNotEmpty
          ? '$base/api/v1/clients?q=${Uri.encodeQueryComponent(q)}'
          : '$base/api/v1/clients',
    );
    final r = await http.get(uri, headers: await _headers(json: false));
    if (r.statusCode == 200) return (jsonDecode(r.body) as List<dynamic>);
    return [];
  }

  // Création d'un client minimal (nom/phone/address) — le dépôt est auto-assigné côté backend pour livreur.
  static Future<Map<String, dynamic>?> createClient({
    required String name,
    String? phone,
    String? address,
  }) async {
    final u = Uri.parse('$base/api/v1/clients');
    final r = await http.post(
      u,
      headers: await _headers(),
      body: jsonEncode({
        'name': name,
        if (phone != null && phone.isNotEmpty) 'phone': phone,
        if (address != null && address.isNotEmpty) 'address': address,
      }),
    );
    if (r.statusCode == 200) return jsonDecode(r.body) as Map<String, dynamic>;
    return null;
  }

  static Future<List<dynamic>> openRounds({int? userId}) async {
    final u = Uri.parse(
      '$base/api/v1/seller-rounds?status=open${userId != null ? '&user_id=$userId' : ''}',
    );
    final r = await http.get(u, headers: await _headers(json: false));
    if (r.statusCode == 200) return (jsonDecode(r.body) as List<dynamic>);
    return [];
  }

  static Future<Map<String, dynamic>?> roundStats(int roundId) async {
    final u = Uri.parse('$base/api/v1/seller-rounds/$roundId/stats');
    final r = await http.get(u, headers: await _headers(json: false));
    if (r.statusCode == 200) {
      return (jsonDecode(r.body) as Map<String, dynamic>);
    }
    return null;
  }

  static Future<List<dynamic>> depotProducts(int depotId) async {
    final u = Uri.parse(
      '$base/api/v1/products?depot_id=$depotId&only_in_stock=1',
    );
    final r = await http.get(u, headers: await _headers(json: false));
    if (r.statusCode == 200) return (jsonDecode(r.body) as List<dynamic>);
    return [];
  }

  static Future<Map<String, dynamic>?> getClient(int id) async {
    final u = Uri.parse('$base/api/v1/clients/$id');
    final r = await http.get(u, headers: await _headers(json: false));
    if (r.statusCode == 200) return jsonDecode(r.body) as Map<String, dynamic>;
    return null;
  }

  static Future<Map<String, dynamic>?> createSale({
    required int depotId,
    required int clientId,
    required int? sellerRoundId,
    required List<Map<String, dynamic>> items,
    required int paymentAmount,
  }) async {
    final u = Uri.parse('$base/api/v1/sales');
    final r = await http.post(
      u,
      headers: await _headers(),
      body: jsonEncode({
        'depot_id': depotId,
        'client_id': clientId,
        'seller_round_id': sellerRoundId,
        'items': items,
        'payment_amount': paymentAmount,
      }),
    );
    if (r.statusCode == 200) return jsonDecode(r.body) as Map<String, dynamic>;
    throw Exception('Erreur vente: ${r.statusCode} ${r.body}');
  }
}
