<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/helpers.php';

// Data do relatório: ?data=AAAA-MM-DD ou o dia atual segundo o relógio do banco.
$hoje = (string)$pdo->query('SELECT CURDATE()')->fetchColumn();
$data = is_string($_GET['data'] ?? null) ? $_GET['data'] : $hoje;
$dt = DateTime::createFromFormat('!Y-m-d', $data);
if (!$dt || $dt->format('Y-m-d') !== $data) { $data = $hoje; $dt = new DateTime($hoje); }
$anterior = (clone $dt)->modify('-1 day')->format('Y-m-d');
$proximo = (clone $dt)->modify('+1 day')->format('Y-m-d');

// Intervalo [dia, dia+1) mantém o uso do índice em data_abertura.
$periodo = 'c.data_abertura >= ? AND c.data_abertura < DATE_ADD(?, INTERVAL 1 DAY)';
$p = [$data, $data];

$s = $pdo->prepare("SELECT COUNT(*) comandas, COALESCE(SUM(c.status='aberta'),0) abertas, COALESCE(SUM(c.status='entregue'),0) entregues,
  AVG(CASE WHEN c.status='entregue' THEN TIMESTAMPDIFF(MINUTE,c.data_abertura,c.data_entrega) END) tempo_medio,
  MAX(CASE WHEN c.status='entregue' THEN TIMESTAMPDIFF(MINUTE,c.data_abertura,c.data_entrega) END) tempo_max
  FROM comandas c WHERE $periodo");
$s->execute($p); $resumo = $s->fetch();

$s = $pdo->prepare("SELECT COALESCE(SUM(i.quantidade*i.preco_unitario),0) faturamento, COALESCE(SUM(i.quantidade),0) unidades
  FROM itens_comanda i JOIN comandas c ON c.id=i.comanda_id WHERE $periodo");
$s->execute($p); $vendas = $s->fetch();
$ticket = (int)$resumo['comandas'] > 0 ? (float)$vendas['faturamento'] / (int)$resumo['comandas'] : 0;

$s = $pdo->prepare("SELECT i.nome_produto, SUM(i.quantidade) qtd, SUM(i.quantidade*i.preco_unitario) receita
  FROM itens_comanda i JOIN comandas c ON c.id=i.comanda_id WHERE $periodo
  GROUP BY i.nome_produto ORDER BY qtd DESC, receita DESC LIMIT 10");
$s->execute($p); $topProdutos = $s->fetchAll();

$s = $pdo->prepare("SELECT COALESCE(pr.categoria,'Sem categoria') categoria, SUM(i.quantidade) qtd, SUM(i.quantidade*i.preco_unitario) receita
  FROM itens_comanda i JOIN comandas c ON c.id=i.comanda_id LEFT JOIN produtos pr ON pr.id=i.produto_id WHERE $periodo
  GROUP BY categoria ORDER BY receita DESC");
$s->execute($p); $categorias = $s->fetchAll();

$s = $pdo->prepare("SELECT HOUR(c.data_abertura) hora, COUNT(*) total FROM comandas c WHERE $periodo GROUP BY hora ORDER BY hora");
$s->execute($p); $porHora = $s->fetchAll(PDO::FETCH_KEY_PAIR);

$maxQtd = $topProdutos ? max(array_map(fn($r) => (int)$r['qtd'], $topProdutos)) : 0;
$maxCat = $categorias ? max(array_map(fn($r) => (float)$r['receita'], $categorias)) : 0;
$maxHora = $porHora ? max($porHora) : 0;
$pct = fn($v, $max) => $max > 0 ? max(2, round($v / $max * 100)) : 0;
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Relatório | Bistrô Sabor da Vila</title><link rel="stylesheet" href="assets/style.css?v=<?=filemtime(__DIR__.'/assets/style.css')?>"></head><body>
<header class="topbar"><div class="wrap topbar-inner"><a class="brand" href="index.php"><span class="brand-icon">SV</span><span>Bistrô <b>Sabor da Vila</b><small>PAINEL DE COMANDAS</small></span></a><nav><a href="index.php">Comandas</a><a href="produtos.php">Produtos</a><a class="nav-active" href="relatorio.php">Relatório</a></nav></div></header>
<main class="wrap"><section class="hero"><div><span class="eyebrow">FECHAMENTO DO DIA</span><h1>Relatório de vendas</h1><p>Faturamento, tempo de preparo e produtos mais pedidos em <?=e($dt->format('d/m/Y'))?>.</p></div><div class="hero-mark">▤</div></section>

<form method="get" class="report-nav"><a class="button secondary" href="relatorio.php?data=<?=e($anterior)?>">← Dia anterior</a><input type="date" name="data" value="<?=e($data)?>" max="<?=e($hoje)?>" aria-label="Data do relatório" onchange="this.form.submit()"><noscript><button class="button secondary" type="submit">Ver</button></noscript><?php if($data<$hoje):?><a class="button secondary" href="relatorio.php?data=<?=e($proximo)?>">Próximo dia →</a><a class="small-link" href="relatorio.php">Hoje</a><?php endif;?></form>

<section class="kpi-grid">
 <div class="kpi"><span>Faturamento</span><strong><?=brl($vendas['faturamento'])?></strong><small><?=(int)$vendas['unidades']?> unidade(s) vendidas</small></div>
 <div class="kpi"><span>Comandas</span><strong><?=(int)$resumo['comandas']?></strong><small><?=(int)$resumo['entregues']?> entregue(s) · <?=(int)$resumo['abertas']?> em preparo</small></div>
 <div class="kpi"><span>Ticket médio</span><strong><?=brl($ticket)?></strong><small>por comanda</small></div>
 <div class="kpi"><span>Tempo médio de entrega</span><strong><?=$resumo['tempo_medio']!==null?e(duracao(round((float)$resumo['tempo_medio']))):'—'?></strong><small><?=$resumo['tempo_max']!==null?'mais demorada: '.e(duracao($resumo['tempo_max'])):'nenhuma entrega no dia'?></small></div>
</section>

<?php if(!(int)$resumo['comandas']):?><div class="panel empty large">Nenhuma comanda registrada em <?=e($dt->format('d/m/Y'))?>.</div><?php else:?>
<section class="report-grid">
 <div class="panel"><div class="section-heading"><div><span class="step">RANKING</span><h2>Produtos mais vendidos</h2></div><span class="count">Top <?=count($topProdutos)?></span></div>
  <?php foreach($topProdutos as $i=>$r):?><div class="bar-row"><div class="bar-label"><span><b><?=$i+1?>.</b> <?=e($r['nome_produto'])?></span><span><?=(int)$r['qtd']?> un · <?=brl($r['receita'])?></span></div><div class="bar"><i style="width:<?=$pct((int)$r['qtd'],$maxQtd)?>%"></i></div></div><?php endforeach;?>
 </div>
 <div class="report-side">
  <div class="panel"><div class="section-heading"><div><span class="step">CARDÁPIO</span><h2>Receita por categoria</h2></div></div>
   <?php foreach($categorias as $r):?><div class="bar-row"><div class="bar-label"><span><?=e($r['categoria'])?></span><span><?=brl($r['receita'])?></span></div><div class="bar bar-alt"><i style="width:<?=$pct((float)$r['receita'],$maxCat)?>%"></i></div></div><?php endforeach;?>
  </div>
  <div class="panel"><div class="section-heading"><div><span class="step">MOVIMENTO</span><h2>Comandas por horário</h2></div></div>
   <?php foreach($porHora as $h=>$t):?><div class="bar-row"><div class="bar-label"><span><?=str_pad((string)$h,2,'0',STR_PAD_LEFT)?>h – <?=str_pad((string)$h,2,'0',STR_PAD_LEFT)?>h59</span><span><?=(int)$t?> comanda(s)</span></div><div class="bar"><i style="width:<?=$pct((int)$t,$maxHora)?>%"></i></div></div><?php endforeach;?>
  </div>
 </div>
</section>
<?php endif;?>
<p class="hint">Valores calculados com o preço salvo em cada item da comanda. Inclui comandas em preparo.</p>
<footer>Projeto didático • UC2 — Práticas DevOps em Projetos Web <span><a href="index.php">Voltar às comandas</a></span></footer></main></body></html>
