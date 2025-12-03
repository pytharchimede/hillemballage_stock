import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:shared_preferences/shared_preferences.dart';

class Api {
  static String base = 'https://app.hillemballage.ci/public';
  static Map<String, dynamic>? _meCache;

  static Future<void> init() async {
    final sp = await SharedPreferences.getInstance();
    final saved = sp.getString('api_base');
    if (saved != null && saved.isNotEmpty) base = saved;
  }

  static Future<void> setBase(String b) async {
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

  static Future<void> clearToken() async {
    final sp = await SharedPreferences.getInstance();
    await sp.remove('api_token');
  }

  static Future<Map<String, dynamic>?> login(
    String email,
    String password,
  ) async {
    final url = Uri.parse('$base/api/v1/auth/login');
    final headers = {'Accept': 'application/json'};
    final body = {'email': email, 'password': password};
    try {
      final res = await http.post(url, headers: headers, body: body);
      Map<String, dynamic>? parsed;
      try {
        parsed = jsonDecode(res.body);
      } catch (_) {}
      String? token = parsed?['token'] ??
          parsed?['access_token'] ??
          (parsed?['data']?['token']);
      if (res.statusCode >= 200 && res.statusCode < 300 && token != null) {
        await setToken(token);
      }
      return parsed;
    } catch (_) {
      return null;
    }
  }

  static Future<Map<String, String>> _headers({bool json = true}) async {
    final tok = await getToken();
    final h = <String, String>{};
    if (json) h['Content-Type'] = 'application/json';
    if (tok != null && tok.isNotEmpty) h['Authorization'] = 'Bearer $tok';
    return h;
  }

  static Future<Map<String, dynamic>?> me() async {
    final u = Uri.parse('$base/api/v1/auth/me');
    final r = await http.get(u, headers: await _headers(json: false));
    if (r.statusCode == 200) {
      try {
        _meCache = jsonDecode(r.body) as Map<String, dynamic>;
      } catch (_) {}
      return _meCache;
    }
    return null;
  }

  static Map<String, dynamic>? get meCached => _meCache;
  static Future<Map<String, dynamic>?> ensureMe() async {
    if (_meCache != null) return _meCache;
    return await me();
  }

  static String resolveImageUrl(String? path) {
    if (path == null || path.isEmpty) return '';
    if (path.startsWith('http://') || path.startsWith('https://')) return path;
    final b = base.endsWith('/') ? base.substring(0, base.length - 1) : base;
    final p = path.startsWith('/') ? path : '/$path';
    return '$b$p';
  }

  // --- Dashboard stats (best-effort across possible endpoints) ---
  static Future<Map<String, dynamic>> statsToday() async {
    // Try multiple known patterns to maximize compatibility
    final paths = [
      '$base/api/v1/stats/today',
      '$base/api/v1/dashboard/stats',
      '$base/api/v1/stats',
    ];
    for (final p in paths) {
      final u = Uri.parse(p);
      final r = await http.get(u, headers: await _headers(json: false));
      if (r.statusCode == 200) {
        try {
          final m = jsonDecode(r.body) as Map<String, dynamic>;
          return m;
        } catch (_) {}
      }
    }
    return {};
  }

  static Future<num?> salesToday() async {
    final candidates = [
      '$base/api/v1/sales?date=today',
      '$base/api/v1/sales/today',
      '$base/api/v1/reports/sales/today',
    ];
    for (final p in candidates) {
      final u = Uri.parse(p);
      final r = await http.get(u, headers: await _headers(json: false));
      if (r.statusCode == 200) {
        try {
          final parsed = jsonDecode(r.body);
          if (parsed is Map && parsed['total'] is num)
            return parsed['total'] as num;
          if (parsed is List &&
              parsed.isNotEmpty &&
              parsed.first['total'] is num) {
            return parsed.first['total'] as num;
          }
        } catch (_) {}
      }
    }
    return null;
  }

  static Future<num?> paymentsToday() async {
    final candidates = [
      '$base/api/v1/payments?date=today',
      '$base/api/v1/finance/receipts/today',
      '$base/api/v1/reports/payments/today',
    ];
    for (final p in candidates) {
      final u = Uri.parse(p);
      final r = await http.get(u, headers: await _headers(json: false));
      if (r.statusCode == 200) {
        try {
          final parsed = jsonDecode(r.body);
          if (parsed is Map && parsed['total'] is num)
            return parsed['total'] as num;
          if (parsed is List &&
              parsed.isNotEmpty &&
              parsed.first['total'] is num) {
            return parsed.first['total'] as num;
          }
        } catch (_) {}
      }
    }
    return null;
  }

  static Future<int?> roundsOngoingCount() async {
    final candidates = [
      '$base/api/v1/rounds?status=ongoing',
      '$base/api/v1/rounds/active',
      '$base/api/v1/tours?status=active',
    ];
    for (final p in candidates) {
      final u = Uri.parse(p);
      final r = await http.get(u, headers: await _headers(json: false));
      if (r.statusCode == 200) {
        try {
          final parsed = jsonDecode(r.body);
          if (parsed is List) return parsed.length;
          if (parsed is Map && parsed['count'] is int)
            return parsed['count'] as int;
        } catch (_) {}
      }
    }
    return null;
  }

  static Future<int?> ordersTodayCount() async {
    final candidates = [
      '$base/api/v1/orders?date=today',
      '$base/api/v1/orders/today',
    ];
    for (final p in candidates) {
      final u = Uri.parse(p);
      final r = await http.get(u, headers: await _headers(json: false));
      if (r.statusCode == 200) {
        try {
          final parsed = jsonDecode(r.body);
          if (parsed is List) return parsed.length;
          if (parsed is Map && parsed['count'] is int)
            return parsed['count'] as int;
        } catch (_) {}
      }
    }
    return null;
  }

  // --- Products ---
  static Future<List<dynamic>> products({
    String q = '',
    String depotId = '',
    bool onlyInStock = false,
  }) async {
    final qp = <String>[];
    if (q.isNotEmpty) qp.add('q=${Uri.encodeComponent(q)}');
    if (depotId.isNotEmpty) qp.add('depot_id=${Uri.encodeComponent(depotId)}');
    if (onlyInStock) qp.add('only_in_stock=1');
    final url = Uri.parse(
        '$base/api/v1/products${qp.isNotEmpty ? '?' + qp.join('&') : ''}');
    final r = await http.get(url, headers: await _headers(json: false));
    if (r.statusCode == 200) {
      try {
        return jsonDecode(r.body) as List<dynamic>;
      } catch (_) {
        return [];
      }
    }
    return [];
  }

  static Future<List<dynamic>> depots() async {
    final url = Uri.parse('$base/api/v1/depots');
    final r = await http.get(url, headers: await _headers(json: false));
    if (r.statusCode == 200) {
      try {
        return jsonDecode(r.body) as List<dynamic>;
      } catch (_) {
        return [];
      }
    }
    return [];
  }

  // --- Clients ---
  static Future<List<dynamic>> clients({String q = ''}) async {
    final qp = <String>[];
    if (q.isNotEmpty) qp.add('q=${Uri.encodeComponent(q)}');
    final url = Uri.parse(
        '$base/api/v1/clients${qp.isNotEmpty ? '?' + qp.join('&') : ''}');
    final r = await http.get(url, headers: await _headers(json: false));
    if (r.statusCode == 200) {
      try {
        return jsonDecode(r.body) as List<dynamic>;
      } catch (_) {
        return [];
      }
    }
    return [];
  }

  static Future<Map<String, dynamic>?> createClient({
    required String name,
    String? phone,
    String? address,
    num? creditLimit,
  }) async {
    final url = Uri.parse('$base/api/v1/clients');
    final r = await http.post(
      url,
      headers: await _headers(),
      body: jsonEncode({
        'name': name,
        if (phone != null) 'phone': phone,
        if (address != null) 'address': address,
        if (creditLimit != null) 'credit_limit': creditLimit,
      }),
    );
    if (r.statusCode >= 200 && r.statusCode < 300) {
      try {
        return jsonDecode(r.body) as Map<String, dynamic>;
      } catch (_) {}
    }
    return null;
  }

  static Future<Map<String, dynamic>?> updateClient({
    required int id,
    required String name,
    String? phone,
    String? address,
    num? creditLimit,
  }) async {
    final url = Uri.parse('$base/api/v1/clients/$id');
    final r = await http.patch(
      url,
      headers: await _headers(),
      body: jsonEncode({
        'name': name,
        if (phone != null) 'phone': phone,
        if (address != null) 'address': address,
        if (creditLimit != null) 'credit_limit': creditLimit,
      }),
    );
    if (r.statusCode >= 200 && r.statusCode < 300) {
      try {
        return jsonDecode(r.body) as Map<String, dynamic>;
      } catch (_) {}
    }
    return null;
  }

  // --- Export helpers ---
  static Future<Uri> productsExportUrl({
    String q = '',
    String depotId = '',
    bool onlyInStock = false,
    String format = 'csv',
  }) async {
    final qp = <String>[];
    if (q.isNotEmpty) qp.add('q=${Uri.encodeComponent(q)}');
    if (depotId.isNotEmpty) qp.add('depot_id=${Uri.encodeComponent(depotId)}');
    if (onlyInStock) qp.add('only_in_stock=1');
    if (format.isNotEmpty) qp.add('format=${Uri.encodeComponent(format)}');
    final tok = await getToken();
    if (tok != null && tok.isNotEmpty)
      qp.add('api_token=${Uri.encodeComponent(tok)}');
    final s =
        '$base/api/v1/products/export${qp.isNotEmpty ? '?' + qp.join('&') : ''}';
    return Uri.parse(s);
  }

  // --- Stocks ---
  static Future<List<dynamic>> productStocks(int productId) async {
    final url = Uri.parse('$base/api/v1/stocks?product_id=$productId');
    final r = await http.get(url, headers: await _headers(json: false));
    if (r.statusCode == 200) {
      try {
        return jsonDecode(r.body) as List<dynamic>;
      } catch (_) {
        return [];
      }
    }
    return [];
  }

  static Future<bool> stockIn({
    required int depotId,
    required int productId,
    required int quantity,
  }) async {
    final url = Uri.parse('$base/api/v1/stock/movement');
    final r = await http.post(
      url,
      headers: await _headers(),
      body: jsonEncode({
        'depot_id': depotId,
        'product_id': productId,
        'type': 'in',
        'quantity': quantity,
      }),
    );
    return r.statusCode >= 200 && r.statusCode < 300;
  }

  static Future<bool> stockTransfer({
    required int fromDepotId,
    required int toDepotId,
    required int productId,
    required int quantity,
  }) async {
    final url = Uri.parse('$base/api/v1/stock/transfer');
    final r = await http.post(
      url,
      headers: await _headers(),
      body: jsonEncode({
        'from_depot_id': fromDepotId,
        'to_depot_id': toDepotId,
        'product_id': productId,
        'quantity': quantity,
      }),
    );
    return r.statusCode >= 200 && r.statusCode < 300;
  }

  // --- Products CRUD ---
  static Future<Map<String, dynamic>?> createProduct({
    required String name,
    String? sku,
    num? unitPrice,
    bool active = true,
    XFile? image,
  }) async {
    final uri = Uri.parse('$base/api/v1/products');
    final tok = await getToken();
    final req = http.MultipartRequest('POST', uri);
    if (tok != null && tok.isNotEmpty) {
      req.headers['Authorization'] = 'Bearer $tok';
    }
    req.fields['name'] = name;
    if (sku != null) req.fields['sku'] = sku;
    if (unitPrice != null) req.fields['unit_price'] = unitPrice.toString();
    req.fields['active'] = active ? '1' : '0';
    if (image != null) {
      final bytes = await image.readAsBytes();
      final filename = image.name;
      req.files.add(
          http.MultipartFile.fromBytes('image', bytes, filename: filename));
    }
    final res = await http.Response.fromStream(await req.send());
    if (res.statusCode >= 200 && res.statusCode < 300) {
      try {
        return jsonDecode(res.body) as Map<String, dynamic>;
      } catch (_) {}
    }
    return null;
  }

  static Future<Map<String, dynamic>?> updateProduct({
    required int id,
    required String name,
    String? sku,
    num? unitPrice,
    bool? active,
    XFile? image,
    bool clearImage = false,
  }) async {
    final uri = Uri.parse('$base/api/v1/products/$id');
    final tok = await getToken();
    final req = http.MultipartRequest('POST', uri);
    // Some backends accept POST with _method=PATCH for multipart
    req.fields['_method'] = 'PATCH';
    if (tok != null && tok.isNotEmpty) {
      req.headers['Authorization'] = 'Bearer $tok';
    }
    req.fields['name'] = name;
    if (sku != null) req.fields['sku'] = sku;
    if (unitPrice != null) req.fields['unit_price'] = unitPrice.toString();
    if (active != null) req.fields['active'] = active ? '1' : '0';
    if (clearImage) req.fields['clear_image'] = '1';
    if (image != null) {
      final bytes = await image.readAsBytes();
      final filename = image.name;
      req.files.add(
          http.MultipartFile.fromBytes('image', bytes, filename: filename));
    }
    final res = await http.Response.fromStream(await req.send());
    if (res.statusCode >= 200 && res.statusCode < 300) {
      try {
        return jsonDecode(res.body) as Map<String, dynamic>;
      } catch (_) {}
    }
    return null;
  }
}
