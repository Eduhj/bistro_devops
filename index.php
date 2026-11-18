<?php
declare(strict_types=1);
require_once __DIR__.'/config/database.php';
require_once __DIR__.'/includes/helpers.php';
$erro=''; $sucesso='';
$produtos=$pdo->query("SELECT id,nome,categoria,preco FROM produtos WHERE ativo=1 ORDER BY categoria,nome")->fetchAll();

if ($_SERVER['REQUEST_METHOD']==='POST') {
 $acao=(string)($_POST['acao']??'');
 try {
  if ($acao==='criar_comanda') {
   $observacao=trim((string)($_POST['observacao']??'')); $itens=[];
   foreach (($_POST['quantidade']??[]) as $pid=>$qraw) {
    $pid=filter_var($pid,FILTER_VALIDATE_INT); $q=filter_var($qraw,FILTER_VALIDATE_INT);
    if (!$pid || !$q || $q<1 || $q>99) continue;
    $s=$pdo->prepare('SELECT id,nome,preco FROM produtos WHERE id=? AND ativo=1'); $s->execute([$pid]); $p=$s->fetch();
    if ($p) $itens[]=['p'=>$p,'q'=>$q];
   }
   if (!$itens) $erro='Selecione ao menos um produto e informe uma quantidade maior que zero.';
   else {
    $pdo->beginTransaction();
    $s=$pdo->prepare('INSERT INTO comandas (observacao) VALUES (?)'); $s->execute([$observacao!==''?$observacao:null]);
    $id=(int)$pdo->lastInsertId();
    $s=$pdo->prepare('UPDATE comandas SET numero=? WHERE id=?'); $s->execute([$id,$id]);
    $si=$pdo->prepare('INSERT INTO itens_comanda (comanda_id,produto_id,nome_produto,quantidade,preco_unitario) VALUES (?,?,?,?,?)');
    foreach($itens as $it) $si->execute([$id,$it['p']['id'],$it['p']['nome'],$it['q'],$it['p']['preco']]);
    $pdo->commit(); header('Location: index.php?criada='.$id); exit;
   }
  } elseif ($acao==='entregar') {
   $id=filter_var($_POST['comanda_id']??'',FILTER_VALIDATE_INT);
   if($id){$s=$pdo->prepare("UPDATE comandas SET status='entregue',data_entrega=NOW() WHERE id=? AND status='aberta'");$s->execute([$id]);}
   header('Location: index.php?entregue=1'); exit;
  } elseif ($acao==='observacao') {
   $id=filter_var($_POST['comanda_id']??'',FILTER_VALIDATE_INT); $obs=trim((string)($_POST['observacao']??''));
   if($id){$s=$pdo->prepare("UPDATE comandas SET observacao=? WHERE id=? AND status='aberta'");$s->execute([$obs!==''?$obs:null,$id]);}
   header('Location: index.php?obs=1'); exit;
  }
 } catch(Throwable $ex) {
  if($pdo->inTransaction()) $pdo->rollBack();
  error_log('Erro nas comandas: '.$ex->getMessage()); $erro='Não foi possível concluir a operação. Confira banco e configuração.';
 }
}
if(isset($_GET['criada'])) $sucesso='Comanda #'.(int)$_GET['criada'].' registrada com sucesso.';
if(isset($_GET['entregue'])) $sucesso='Comanda marcada como entregue.';
if(isset($_GET['obs'])) $sucesso='Observação atualizada.';
// Filtros de acompanhamento: status (aberta/entregue) e número da comanda.
$filtro=is_string($_GET['status']??null)&&in_array($_GET['status'],['aberta','entregue'],true)?$_GET['status']:'';
$busca=is_string($_GET['busca']??null)?filter_var(trim($_GET['busca']),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
$where=[];$params=[];
if($filtro!==''){$where[]='c.status=?';$params[]=$filtro;}
if($busca){$where[]='c.numero=?';$params[]=$busca;}
$contagem=$pdo->query("SELECT status,COUNT(*) FROM comandas GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$contagem['todas']=array_sum($contagem);
$s=$pdo->prepare("SELECT c.*,COALESCE(SUM(i.quantidade*i.preco_unitario),0) total,COALESCE(SUM(i.quantidade),0) unidades,TIMESTAMPDIFF(MINUTE,c.data_abertura,COALESCE(c.data_entrega,NOW())) minutos FROM comandas c LEFT JOIN itens_comanda i ON i.comanda_id=c.id".($where?' WHERE '.implode(' AND ',$where):'')." GROUP BY c.id ORDER BY c.id DESC LIMIT 30");
$s->execute($params);$comandas=$s->fetchAll();
$filtroUrl=fn(string $st)=>'index.php'.($st!==''||$busca?'?'.http_build_query(array_filter(['status'=>$st,'busca'=>$busca?:''])):'');
$itensPor=[];
if($comandas){$ids=array_map(fn($c)=>(int)$c['id'],$comandas);$ph=implode(',',array_fill(0,count($ids),'?'));$s=$pdo->prepare("SELECT * FROM itens_comanda WHERE comanda_id IN ($ph) ORDER BY id");$s->execute($ids);foreach($s->fetchAll() as $i)$itensPor[$i['comanda_id']][]=$i;}
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comandas | Bistrô Sabor da Vila</title><link rel="stylesheet" href="assets/style.css?v=<?=filemtime(__DIR__.'/assets/style.css')?>"></head><body>
<header class="topbar"><div class="wrap topbar-inner"><a class="brand" href="index.php"><span class="brand-icon">SV</span><span>Bistrô <b>Sabor da Vila</b><small>PAINEL DE COMANDAS</small></span></a><nav><a class="nav-active" href="index.php">Comandas</a><a href="produtos.php">Produtos</a><a href="relatorio.php">Relatório</a></nav></div></header>
<main class="wrap"><section class="hero"><div><span class="eyebrow">OPERAÇÃO DA COZINHA</span><h1>Comandas do restaurante</h1><p>Registre pedidos, acompanhe o preparo e marque pedidos entregues.</p></div><div class="hero-mark">☕</div></section>
<?php if($erro):?><div class="alert error"><?=e($erro)?></div><?php endif;?><?php if($sucesso):?><div class="alert success"><?=e($sucesso)?></div><?php endif;?>
<section class="layout"><div class="panel"><div class="panel-heading"><div><span class="step">01 / NOVO PEDIDO</span><h2>Abrir comanda</h2></div><span class="pill">Cozinha</span></div>
<form method="post"><input type="hidden" name="acao" value="criar_comanda">
<?php if(!$produtos):?><div class="empty">Nenhum produto ativo. Cadastre produtos no painel Produtos.</div><?php else:?><input type="search" id="busca-produto" class="product-search" placeholder="Buscar produto ou categoria..." aria-label="Buscar produto"><div class="product-list"><?php $cat='';foreach($produtos as $p):if($cat!==$p['categoria']):$cat=$p['categoria'];?><div class="category-label" data-categoria="<?=e($cat)?>"><?=e($cat)?></div><?php endif;?><label class="product-row" data-busca="<?=e(mb_strtolower($p['nome'].' '.$p['categoria'],'UTF-8'))?>" data-categoria="<?=e($cat)?>"><span class="product-info"><strong><?=e($p['nome'])?></strong><small><?=brl($p['preco'])?></small></span><span class="quantity"><span>Qtd.</span><input type="number" name="quantidade[<?=(int)$p['id']?>]" min="0" max="99" value="0" data-preco="<?=e($p['preco'])?>" aria-label="Quantidade de <?=e($p['nome'])?>"></span></label><?php endforeach;?></div><div class="empty search-empty" id="busca-sem-resultados" hidden>Nenhum produto encontrado.</div>
<div class="live-total" id="resumo-pedido"><span id="resumo-itens">Nenhum item selecionado</span><strong id="resumo-total"><?=brl(0)?></strong></div>
<label class="field-label" for="obs">Observação geral da cozinha</label><textarea id="obs" name="observacao" rows="3" maxlength="2000" placeholder="Ex.: sem cebola, molho à parte..."></textarea><button class="button primary full" type="submit">＋ Registrar comanda</button><?php endif;?></form><p class="hint">O preço do item é salvo na comanda, preservando o valor histórico mesmo que o cardápio mude.</p></div>
<div class="orders-column"><div class="section-heading"><div><span class="step">02 / ACOMPANHAMENTO</span><h2>Últimas comandas</h2></div><span class="count"><?=count($comandas)?> / 30</span></div>
<div class="order-filters"><nav class="filter-tabs" aria-label="Filtrar por status"><?php foreach([''=>'Todas','aberta'=>'Em preparo','entregue'=>'Entregues'] as $st=>$rot):?><a class="<?=$filtro===$st?'filter-active':''?>" href="<?=e($filtroUrl($st))?>"><?=$rot?> <span><?=(int)($contagem[$st===''?'todas':$st]??0)?></span></a><?php endforeach;?></nav>
<form method="get" class="filter-search"><?php if($filtro):?><input type="hidden" name="status" value="<?=e($filtro)?>"><?php endif;?><input type="search" name="busca" inputmode="numeric" placeholder="Nº da comanda" value="<?=$busca?(int)$busca:''?>" aria-label="Buscar comanda pelo número"><button class="button secondary" type="submit">Buscar</button><?php if($busca):?><a class="small-link" href="<?=e($filtro?'index.php?status='.$filtro:'index.php')?>">Limpar</a><?php endif;?></form></div>
<?php if(!$comandas):?><div class="panel empty large"><?php if($filtro||$busca):?>Nenhuma comanda encontrada para este filtro.<br><span><a href="index.php">Ver todas as comandas</a></span><?php else:?>Nenhuma comanda registrada ainda.<br><span>Use o formulário para criar o primeiro pedido.</span><?php endif;?></div><?php else:foreach($comandas as $c):$atrasada=$c['status']==='aberta'&&(int)$c['minutos']>=MINUTOS_ATRASO;?><article class="order-card <?=$c['status']==='entregue'?'order-done':''?> <?=$atrasada?'order-late':''?>"><div class="order-top"><div><span class="order-number">COMANDA #<?=(int)$c['numero']?></span><div class="order-date"><?=e(date('d/m/Y H:i',strtotime($c['data_abertura'])))?><?php if($c['status']==='aberta'):?> <span class="elapsed <?=$atrasada?'elapsed-late':''?>" title="Tempo desde a abertura">⏱ há <?=e(duracao($c['minutos']))?><?=$atrasada?' — atrasada':''?></span><?php endif;?></div></div><span class="status <?=$c['status']==='entregue'?'status-done':'status-open'?>"><?=$c['status']==='entregue'?'✓ Entregue':'● Em preparo'?></span></div>
<div class="order-items"><?php foreach(($itensPor[$c['id']]??[]) as $i):?><div class="order-item"><span><b><?=(int)$i['quantidade']?>×</b> <?=e($i['nome_produto'])?></span><strong><?=brl($i['quantidade']*$i['preco_unitario'])?></strong></div><?php endforeach;?></div>
<div class="order-total"><span><?=(int)$c['unidades']?> item(ns)</span><strong><?=brl($c['total'])?></strong></div>
<?php if($c['observacao']):?><div class="observation"><b>Observação:</b> <?=nl2br(e($c['observacao']))?></div><?php endif;?>
<?php if($c['status']==='entregue'):?><div class="delivered-note">Entregue em <?=e(date('d/m/Y H:i',strtotime($c['data_entrega'])))?> · preparo em <?=e(duracao($c['minutos']))?></div><?php else:?><div class="order-actions"><form method="post" onsubmit="return confirm('Marcar comanda #<?=(int)$c['numero']?> como entregue?')"><input type="hidden" name="acao" value="entregar"><input type="hidden" name="comanda_id" value="<?=(int)$c['id']?>"><button class="button done-button" type="submit">✓ Marcar entregue</button></form><details><summary>Editar observação</summary><form method="post" class="edit-note"><input type="hidden" name="acao" value="observacao"><input type="hidden" name="comanda_id" value="<?=(int)$c['id']?>"><textarea name="observacao" rows="2" maxlength="2000"><?=e($c['observacao'])?></textarea><button class="button secondary" type="submit">Salvar observação</button></form></details></div><?php endif;?></article><?php endforeach;endif;?></div></section>
<footer>Projeto didático • UC2 — Práticas DevOps em Projetos Web <span>PHP + MySQL</span></footer></main>
<script>
// Total ao vivo e busca de produtos no formulário de nova comanda.
(function(){
 var inputs=document.querySelectorAll('.product-list input[data-preco]');if(!inputs.length)return;
 var fmt=new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL'});
 var elItens=document.getElementById('resumo-itens'),elTotal=document.getElementById('resumo-total'),box=document.getElementById('resumo-pedido');
 function normalizar(texto){return texto.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();}
 function atualizar(){
  var total=0,unid=0,prod=0;
  inputs.forEach(function(i){var q=parseInt(i.value,10);if(q>0&&q<=99){total+=q*parseFloat(i.dataset.preco);unid+=q;prod++;}i.closest('.product-row').classList.toggle('row-selected',q>0);});
  elItens.textContent=unid?unid+' unidade(s) de '+prod+' produto(s)':'Nenhum item selecionado';
  elTotal.textContent=fmt.format(total);box.classList.toggle('has-items',unid>0);
 }
 inputs.forEach(function(i){i.addEventListener('input',atualizar);});atualizar();
 var busca=document.getElementById('busca-produto');
 busca.addEventListener('keydown',function(e){if(e.key==='Enter')e.preventDefault();});
 busca.addEventListener('input',function(){
  var t=normalizar(busca.value.trim()),visiveis={},encontrados=0;
  document.querySelectorAll('.product-row').forEach(function(r){var ok=!t||normalizar(r.dataset.busca).indexOf(t)!==-1;r.hidden=!ok;if(ok){visiveis[r.dataset.categoria]=1;encontrados++;}});
  document.querySelectorAll('.category-label').forEach(function(c){c.hidden=!visiveis[c.dataset.categoria];});
  document.getElementById('busca-sem-resultados').hidden=encontrados>0;
 });
})();
</script></body></html>
