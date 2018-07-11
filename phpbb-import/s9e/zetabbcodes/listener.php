<?php

/**
* @package   s9e\zetabbcodes
* @copyright Copyright (c) 2018 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\zetabbcodes;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use s9e\TextFormatter\Configurator\Items\UnsafeTemplate;

class listener implements EventSubscriberInterface
{
	public static function getSubscribedEvents()
	{
		return [
			'core.text_formatter_s9e_configure_after'  => 'afterConfigure',
			'core.text_formatter_s9e_configure_before' => 'beforeConfigure',
			'core.text_formatter_s9e_render_before' => 'beforeRender',
		];
	}

	public function afterConfigure($event)
	{
		$configurator = $event['configurator'];

		// Interpret [quote=foo,(time=123)] as [quote author=foo time=123]
		$configurator->tags['quote']->attributePreprocessors->add(
			'author',
			'/^(?<author>.+),\\(time=(?<time>\\d+)\\)$/'
		);

		// Interpret [img=10,20] as [img width=10 height=20]
		$imgTag = '[img={SIMPLETEXT;optional} width={NUMBER;optional} height={NUMBER;optional}]{URL}[/img]';
		$imgHtml = '<img src="{URL}">
				<xsl:choose>
					<xsl:when test="@width">
						<xsl:attribute name="width">
							<xsl:value-of select="@width"/>
						</xsl:attribute>
					</xsl:when>
				</xsl:choose>
				<xsl:choose>
					<xsl:when test="@height">
						<xsl:attribute name="height">
							<xsl:value-of select="@height"/>
						</xsl:attribute>
					</xsl:when>
				</xsl:choose>
			<xsl:apply-templates/>
		</img>';
		$configurator->BBCodes->addCustom($imgTag, new UnsafeTemplate($imgHtml));

		$configurator->tags['img']->attributePreprocessors->add(
			'img',
			'/^(?<width>\\d+),(?<height>\\d+)$/'
		);
	}

	public function beforeRender($event) {
		global $user, $auth;

		$username = $user->data['username'];
		$isMember = $user->data['is_registered'];
		$isStaff = $auth->acl_gets('a_', 'm_');

		$renderer = $event['renderer']->get_renderer();
		$renderer->setParameter('USERNAME', $username);
		$renderer->setParameter('IS_MEMBER', $isMember);
		$renderer->setParameter('IS_STAFF', $isStaff);
	}

	public function beforeConfigure($event)
	{
		$configurator = $event['configurator'];

		$bbcodes = [
			[
				'[s]{TEXT}[/s]',
				'<del>{TEXT}</del>'
			],
			[
				'[sub]{TEXT}[/sub]',
				'<sub>{TEXT}</sub>'
			],
			[
				'[sup]{TEXT}[/sup]',
				'<sup>{TEXT}</sup>'
			],
			[
				'[big]{TEXT}[/big]',
				'<big>{TEXT}</big>'
			],
			[
				'[small]{TEXT}[/small]',
				'<small>{TEXT}</small>'
			],
			[
				'[bgcolor={COLOR}]{TEXT}[/bgcolor]',
				'<span style="background-color:{COLOR}">{TEXT}</span>'
			],
			[
				'[center]{TEXT}[/center]',
				'<span style="display:block;text-align:center">{TEXT}</span>'
			],
			[
				'[left]{TEXT}[/left]',
				'<span style="display:block;text-align:left">{TEXT}</span>'
			],
			[
				'[right]{TEXT}[/right]',
				'<span style="display:block;text-align:right">{TEXT}</span>'
			],
			[
				'[font={IDENTIFIER}]{TEXT}[/font]',
				'<span style="font-family:{IDENTIFIER}">{TEXT}</span>'
			],
			[
				'[hr]',
				'<hr>'
			],
			[
				'[spoiler={SIMPLETEXT;optional}]{TEXT}[/spoiler]',
				'<div class="spoiler_toggle" onclick="$(this).next().toggle();">
				  <xsl:choose>
				    <xsl:when test="@spoiler">
				      <xsl:value-of select="@spoiler"/>
				    </xsl:when>
				    <xsl:otherwise>
				      <xsl:text>Spoiler: click to toggle</xsl:text>
				    </xsl:otherwise>
				  </xsl:choose>
				</div>
				<div class="spoiler" style="display:none;">{TEXT}</div>'
			],
			[
				'[nocode #ignoreTags=true]{TEXT}[/nocode]',
				'{TEXT}'
			],
			[
				'[html]{TEXT}[/html]',
				'<pre data-hljs="" data-s9e-livepreview-postprocess="if(\'undefined\'!==typeof hljs)hljs._hb(this)"><code>
				    <xsl:attribute name="class">language-html</xsl:attribute>
				    <xsl:apply-templates />
				</code></pre>
				<script>if("undefined"!==typeof hljs)hljs._ha();else if("undefined"===typeof hljsLoading){hljsLoading=1;var a=document.getElementsByTagName("head")[0],e=document.createElement("link");e.type="text/css";e.rel="stylesheet";e.href="//cdnjs.cloudflare.com/ajax/libs/highlight.js/9.7.0/styles/default.min.css";a.appendChild(e);e=document.createElement("script");e.type="text/javascript";e.onload=function(){var d={},f=0;hljs._hb=function(b){b.removeAttribute("data-hljs");var c=b.innerHTML;c in d?b.innerHTML=d[c]:(7&lt;++f&amp;&amp;(d={},f=0),hljs.highlightBlock(b.firstChild),d[c]=b.innerHTML)};hljs._ha=function(){for(var b=document.querySelectorAll("pre[data-hljs]"),c=b.length;0&lt;c;)hljs._hb(b.item(--c))};hljs._ha()};e.async=!0;e.src="//cdnjs.cloudflare.com/ajax/libs/highlight.js/9.7.0/highlight.min.js";a.appendChild(e)}</script>'
			],
			/*[
				"[QUOTE
					author={TEXT1;optional}
					post_id={UINT;optional}
					post_url={URL;optional;postFilter=#false}
					profile_url={URL;optional;postFilter=#false}
					time={UINT;optional}
					url={URL;optional}
					user_id={UINT;optional}
					author={PARSE=/^\\[url=(?'url'.*?)](?'author'.*)\\[\\/url]$/i}
					author={PARSE=/^\\[url](?'author'(?'url'.*?))\\[\\/url]$/i}
					author={PARSE=/(?'url'https?:\\/\\/[^[\\]]+)/i}
				]{TEXT2}[/QUOTE]",
				'<blockquote class="quote_blockquote">
					<dl>
						<dt>
							<xsl:choose>
								<xsl:when test="@author">
									<xsl:value-of select="@author"/>
								</xsl:when>
								<xsl:otherwise>
									<xsl:text>Quote:</xsl:text>
								</xsl:otherwise>
							</xsl:choose>
						</dt>
						<dd>
							<xsl:choose>
								<xsl:when test="@date">
									<xsl:value-of select="@date"/>
								</xsl:when>
								<xsl:otherwise>
									<xsl:text>&nbsp;</xsl:text>
								</xsl:otherwise>
							</xsl:choose>
						</dd>
					</dl>
					<div>
						{TEXT2}
					</div>
				</blockquote>'
			],*/
			[
				'[BORDER={PARSE=/^(?<border>.+),(?<width>.+),(?<style>.+)$/,/^(?<border>.+),(?<width>.+)$/} width={NUMBER;optional} style={IDENTIFIER;optional}]{TEXT}[/BORDER]',
				'<span>
					<xsl:attribute name="style">
						<xsl:text>border:</xsl:text>
						<xsl:text> </xsl:text>
						<xsl:choose>
							<xsl:when test="@width">
								<xsl:value-of select="@width"/>
							</xsl:when>
							<xsl:otherwise>
								<xsl:text>1</xsl:text>
							</xsl:otherwise>
						</xsl:choose>
						<xsl:text>px </xsl:text>
						<xsl:choose>
							<xsl:when test="@style">
								<xsl:value-of select="@style"/>
							</xsl:when>
							<xsl:otherwise>
								<xsl:text>solid</xsl:text>
							</xsl:otherwise>
						</xsl:choose>
						<xsl:text> </xsl:text>
						<xsl:choose>
							<xsl:when test="@border">
								<xsl:value-of select="@border"/>
							</xsl:when>
							<xsl:otherwise>
								<xsl:text>black</xsl:text>
							</xsl:otherwise>
						</xsl:choose>
					</xsl:attribute>
					<xsl:apply-templates/>
				</span>'
			],
			[
				'[me]',
				'<xsl:value-of select="$USERNAME"/>'
			],
			[
				'[member]{TEXT}[/member]',
				'<xsl:if test="$IS_MEMBER=1">{TEXT}</xsl:if>'
			],
			[
				'[staff]{TEXT}[/staff]',
				'<xsl:if test="$IS_STAFF=1">{TEXT}</xsl:if>'
			]/*,
			[
				'[img={SIMPLETEXT;optional} width={NUMBER;optional} height={NUMBER;optional}]{URL}[/img]',
				'<img src="{URL}">
				    <xsl:choose>
				      <xsl:when test="@width">
				        <xsl:attribute name="width">
				          <xsl:value-of select="@width"/>
				        </xsl:attribute>
				      </xsl:when>
				    </xsl:choose>
				    <xsl:choose>
				      <xsl:when test="@height">
				        <xsl:attribute name="height">
				          <xsl:value-of select="@height"/>
				        </xsl:attribute>
				      </xsl:when>
				    </xsl:choose>
				  <xsl:apply-templates/>
				</img>'
			]*/
		];

		foreach ($bbcodes as list($usage, $template))
		{
			$configurator->BBCodes->addCustom($usage, new UnsafeTemplate($template));
		}
	}
}
