export function normalizeFa(value = '') {
  return String(value)
    .replace(/ي/g, 'ی').replace(/ك/g, 'ک')
    .replace(/[\u200c\u200d]/g, ' ')
    .replace(/\s+/g, ' ').trim().toLowerCase();
}

export function productKey(product) {
  if (product.productId) return `product:${String(product.productId).trim()}`;
  if (product.url) return `url:${canonicalProductUrl(product.url)}`;
  return [product.image, normalizeFa(product.merchant), normalizeFa(product.title)].join('|');
}

function canonicalProductUrl(value) {
  try {
    const url = new URL(value, 'https://app.snapppay.ir');
    url.search = '';
    url.hash = '';
    return url.pathname.replace(/\/$/, '');
  } catch {
    return String(value).trim().split(/[?#]/, 1)[0].replace(/\/$/, '');
  }
}

export function ownsProduct(product, identity) {
  const productIds = new Set((identity.productIds || []).map(value => String(value).trim()));
  if (product.productId && productIds.has(String(product.productId).trim())) return true;
  const sellerIds = new Set((identity.sellerIds || []).map(value => String(value).trim()));
  if (product.sellerId && sellerIds.has(String(product.sellerId).trim())) return true;
  const merchant = normalizeFa(product.merchant);
  const aliases = (identity.merchantAliases || []).map(normalizeFa);
  if (aliases.includes(merchant)) return true;
  return (identity.productKeys || []).includes(productKey(product));
}

export function summarizeRank(snapshot, identity) {
  const ownMerchant = (snapshot.merchants || []).findIndex(name =>
    (identity.merchantAliases || []).map(normalizeFa).includes(normalizeFa(name)));
  const products = snapshot.products || [];
  const own = products.map((product, index) => ({ product, index }))
    .filter(({ product }) => ownsProduct(product, identity));
  const ads = products.filter(product => product.isAd);
  const first = own[0];
  const banner = (snapshot.banners || []).findIndex(name =>
    (identity.merchantAliases || []).map(normalizeFa).includes(normalizeFa(name)));
  const queryMatches = normalizeFa(snapshot.displayedQuery) === normalizeFa(snapshot.query);
  // An incomplete scan cannot prove that an absent product lost its position.
  const hasProducts = products.length > 0;
  const explicitEmpty = snapshot.explicitNoResults === true && snapshot.scanComplete === true;
  const sourceValid = !snapshot.sourceWarning && queryMatches && snapshot.sequenceReliable === true &&
    ((hasProducts && (own.length > 0 || snapshot.scanComplete === true)) || explicitEmpty);
  const firstOwnAd = own.find(({ product }) => product.isAd);
  const absoluteAdSlot = firstOwnAd ? firstOwnAd.index + 1 : null;
  return {
    query: snapshot.query,
    observedAt: snapshot.observedAt,
    sourceValid,
    sourceWarning: snapshot.sourceWarning || null,
    scannedProducts: products.length,
    scanComplete: snapshot.scanComplete === true,
    merchantCarouselRank: ownMerchant < 0 ? null : ownMerchant + 1,
    bannerRank: banner < 0 ? null : banner + 1,
    gridRank: first ? first.index + 1 : null,
    adRank: firstOwnAd
      ? ads.findIndex(p => productKey(p) === productKey(firstOwnAd.product)) + 1 : null,
    absoluteAdSlot,
    ownProducts: own.map(({ product, index }) => ({
      rank: index + 1, merchant: product.merchant, title: product.title,
      isAd: !!product.isAd, productId: product.productId || null,
      sellerId: product.sellerId || null, url: product.url || null,
      price: product.price || null, originalPrice: product.originalPrice || null,
      discount: product.discount || null, rating: product.rating || null,
      reviewCount: product.reviewCount || null, availability: product.availability || null,
      key: productKey(product),
    })),
    topCompetitors: products.map((product, index) => ({
      rank: index + 1, merchant: product.merchant, title: product.title,
      isAd: !!product.isAd, productId: product.productId || null,
      sellerId: product.sellerId || null, url: product.url || null,
      price: product.price || null, originalPrice: product.originalPrice || null,
      discount: product.discount || null, rating: product.rating || null,
      reviewCount: product.reviewCount || null, availability: product.availability || null,
    })).filter(product => !ownsProduct(product, identity)).slice(0, 20),
    rankScope: snapshot.scanComplete ? 'all_loaded_results' : 'first_scanned_products',
  };
}
