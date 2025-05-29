import { __ } from "@wordpress/i18n"
import SbUtils from "../../Utils/SbUtils";
import Button from "../Common/Button";
import Header from "../Global/Header";
import AboutusScreenContext from "../Context/AboutusScreenContext";
import { useState } from "react";

const AboutPage = ( { sbCustomizer, editorTopLoader, editorNotification, editorConfirmDialog, isPro, upsellModal,apis, noticeBar, noticeBarMemo} ) => {

    const [ currentTreatedPlugin, setCurrentTreatedPlugin ] = useState( null );

    const [ smashPlugins, setSmashPlugins ] = useState( sbCustomizer?.plugins );
    const [ recommendedPlugins, setRecommendedPlugins ] = useState( sbCustomizer?.recommendedPlugins);

    const utmSource = isPro ? 'reviews-pro' : 'reviews-free'

    const installPlugin = ( plugin ) => {

        let formData = {
            action : 'sbr_install_plugin',
            plugin : plugin
        },
        notificationsContent = {
            type : 'success',
            icon : 'success',
            text : __('Plugin Installed', 'sb-customizer' )
        }

        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( data.success === false ){
                    data = data?.data[0] ?? data;
                    notificationsContent  = {
                        type : 'error',
                        icon : 'notice',
                        text : data?.message ? data?.message :  __('Unknown Error', 'sb-customizer' )
                    }
                    SbUtils.applyNotification( notificationsContent , editorNotification );
                }else{
                    data = data?.data ?? data;
                    notificationsContent  = {
                        type : 'success',
                        icon : 'success',
                        text : data?.message ? data?.message :  __('Plugin Installed', 'sb-customizer' )
                    }
                    SbUtils.applyNotification( notificationsContent , editorNotification );
                    setSmashPlugins( {...data?.plugins} );
                    setRecommendedPlugins( {...data?.recommendedPlugins} );

                }
                setCurrentTreatedPlugin( null )
            },
            editorTopLoader,
            null,
            null
        )
    }

    const activateDeactivatePlugin = ( plugin, action ) => {
        let formData = {
            action : action === 'activate' ? 'sbr_activate_plugin' : 'sbr_deactivate_plugin',
            plugin : plugin
        },
        notificationsContent = {
            type : 'success',
            icon : 'success',
            text : action === 'activate' ? __('Plugin Activated', 'sb-customizer' ) : __('Plugin Deactivated', 'sb-customizer')
        }

        SbUtils.ajaxPost(
            sbCustomizer.ajaxHandler,
            formData,
            ( data ) => { //Call Back Function
                if( data.success === false ){
                    data = data?.data[0] ?? data;
                    notificationsContent  = {
                        type : 'error',
                        icon : 'notice',
                        text : data?.message ? data?.message :  __('Unknown Error', 'sb-customizer' )
                    }
                    SbUtils.applyNotification( notificationsContent , editorNotification );
                }else{
                    data = data?.data ?? data;
                    SbUtils.applyNotification( notificationsContent , editorNotification );
                    setSmashPlugins( {...data?.plugins} );
                    setRecommendedPlugins( {...data?.recommendedPlugins} );

                }
                setCurrentTreatedPlugin( null )
            },
            editorTopLoader,
            null,
            null
        )
    }



    return (
        <AboutusScreenContext.Provider
            value={{
                sbCustomizer,
                editorTopLoader,
                editorNotification,
                editorConfirmDialog,
                isPro,
                upsellModal,
                apis,
                noticeBarMemo,
                noticeBar
            }}
        >
            <Header
                className='sb-dashboard-header'
                heading={ __( 'About Us', 'sb-customizer' )}
                editorTopLoader={ editorTopLoader }
                topNoticeBar={noticeBar.topNoticeBar}
                showHelpButton={ true }
                setTopNoticeBar={ (e) => {
                    noticeBar.setTopNoticeBar(e)
                } }
            />

            <section className='sb-full-wrapper sb-fs sb-fs'>
                <section className='sb-small-wrapper'>
                    {
                        sbCustomizer?.adminNoticeContent !== null &&
                        <section className='sb-fs'
                        dangerouslySetInnerHTML={{__html: sbCustomizer?.adminNoticeContent }}></section>
                    }

                    <section className='sb-dashboard-heading sb-fs'>
                        <h2 className='sb-h2'>{ __( 'About Us', 'sb-customizer' ) }</h2>
                    </section>

                    <section className='sb-whitebox-ctn sb-fs'>

                        <div className='sb-team-avatar'>
                            <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/team-avatar.png' } alt={__( 'SmashBalloon Team', 'sb-customizer' )} />
                        </div>

                        <div className='sb-team-info sb-fs'>
                            <div className='sb-team-left'>
                                <h3 className='sb-h3'>
                                    {__( 'At Smash Balloon, we build software that helps you create beautiful responsive social media feeds for your website in minutes.', 'sb-customizer' )}
                                </h3>
                            </div>
                            <div className='sb-team-right'>
                                <p className='sb-light-text2 sb-text-small'>{__( 'We\'re on a mission to make it super simple to add social media feeds in WordPress. No more complicated setup steps, ugly iframe widgets, or negative page speed scores.', 'sb-customizer' )}</p>
                                <p className='sb-light-text2 sb-text-small'>{__( 'Our plugins aren\'t just easy to use, but completely customizable, reliable, and fast! Which is why over 1.6 million awesome users, just like you, choose to use them on their site.', 'sb-customizer' )}</p>
                            </div>
                        </div>

                    </section>

                    <section className='sb-aboutus-heading sb-fs' id='sb-pluginlist-ctn'>
                        <h3 className='sb-h3'>{ __( 'Our Other Social Media Feed Plugins', 'sb-customizer' ) }</h3>
                        <span className='sb-small-p sb-fs'>{ __( 'We\'re more than just a Reviews plugin! Check out our other plugins and add more content to your site.', 'sb-customizer' ) }</span>
                    </section>

                    <section className='sb-pluginlist-ctn sb-fs'>
                        {
                            smashPlugins &&
                            Object.values(smashPlugins).map( (plugin, key) => {
                                const pluginID = Object.keys(smashPlugins)[key];
                                return (
                                    <div className='sb-plugin-item sb-whitebox-ctn' key={key}>
                                        <div className='sb-plugin-item-icon'>
                                            <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/' + plugin.icon } alt={__( 'Facebook Plugin', 'sb-customizer' )} />
                                        </div>
                                        <div className='sb-plugin-item-info'>
                                            <strong className='sb-standard-p sb-fs sb-bold'> { plugin.title + (plugin.type !== 'none' ? ' Pro' : '') } </strong>
                                            <span className='sb-small-p sb-fs'>{ plugin.description }</span>
                                            <div className='sb-plugin-item-actions sb-fs'>
                                                {
                                                   (  plugin.type === 'none' &&  plugin.activated === 'none' ) &&
                                                    <Button
                                                        type={ currentTreatedPlugin === pluginID ? 'secondary' : 'primary' }
                                                        icon={ currentTreatedPlugin === pluginID ? 'loader' : false }
                                                        size='small'
                                                        loading={ currentTreatedPlugin === pluginID }
                                                        text={ __( 'Install', 'sb-customizer' ) }
                                                        boxshadow={false}
                                                        onClick={ () => {
                                                            setCurrentTreatedPlugin( pluginID );
                                                            installPlugin( plugin.download_plugin )
                                                        } }
                                                    />
                                                }
                                                {
                                                    plugin.type === 'pro'  &&
                                                    <Button
                                                        type='secondary'
                                                        size='small'
                                                        icon='success'
                                                        text={ __( 'Installed', 'sb-customizer' ) }
                                                        disabled={true}
                                                        boxshadow={false}
                                                    />
                                                }
                                                {
                                                   (  plugin.type === 'pro'  && !plugin.activated ) &&
                                                    <Button
                                                        type='primary'
                                                        size='small'
                                                        text={ __( 'Activate', 'sb-customizer' ) }
                                                        icon={ currentTreatedPlugin === pluginID ? 'loader' : false }
                                                        loading={ currentTreatedPlugin === pluginID }
                                                        onClick={ () => {
                                                            setCurrentTreatedPlugin( pluginID );
                                                            activateDeactivatePlugin( plugin?.plugin, 'activate' )
                                                        } }
                                                    />
                                                }
                                                {
                                                   plugin.type === 'free' &&
                                                    <Button
                                                        type='primary'
                                                        size='small'
                                                        text={ __( 'Upgrade to Pro', 'sb-customizer' ) }
                                                        link={ plugin.link }
                                                        target='_blank'
                                                    />
                                                }
                                            </div>
                                        </div>
                                    </div>
                                )
                            } )
                        }
                    </section>

                    <section className='sb-socialwall-item sb-whitebox-ctn sb-fs'>
                        <div className='sb-socialwall-item-icon'>
                            <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/social-wall-graphic.png'} alt={__( 'Social Wall', 'sb-customizer' )} />
                        </div>
                        <div className='sb-plugin-item-info'>
                            <h4 className='sb-h4'>{__( 'Social Wall', 'sb-customizer' )} </h4>
                            <span className='sb-small-p sb-fs'>{__( 'Combine feeds from all of our plugins into a single wall', 'sb-customizer' )}</span>
                            <div className='sb-plugin-item-actions sb-fs'>
                                <Button
                                    type='primary'
                                    size='small'
                                    text={ __( 'Install', 'sb-customizer' ) }
                                    link={'https://smashballoon.com/social-wall/?reviews&utm_campaign='+utmSource+'&utm_source=about&utm_medium=social-wall'}
                                    target='_blank'
                                />
                            </div>
                        </div>
                    </section>


                    <section className='sb-aboutus-heading sb-fs'>
                        <h3 className='sb-h3'>{ __( 'Plugins we recommend', 'sb-customizer' ) }</h3>
                    </section>

                    <section className='sb-pluginlist-ctn sb-recommended-pluginlist-ctn sb-fs'>
                        {
                            recommendedPlugins &&
                            Object.values(recommendedPlugins).map( (plugin, key) => {
                                const pluginID = Object.keys(recommendedPlugins)[key];

                                return (
                                    <div className='sb-plugin-item sb-whitebox-ctn' key={key}>
                                        <div className='sb-plugin-item-icon'>
                                            <img src={ window.sb_customizer.assetsURL + 'sb-customizer/assets/images/' + plugin.icon } alt={__( 'Facebook Plugin', 'sb-customizer' )} />
                                        </div>
                                        <div className='sb-plugin-item-info'>
                                            <strong className='sb-standard-p sb-fs sb-bold'> { plugin.title } </strong>
                                            <span className='sb-small-p sb-fs'>{ plugin.description }</span>
                                            <div className='sb-plugin-item-actions sb-fs'>
                                                {
                                                   !plugin.installed  &&
                                                    <Button
                                                        type='secondary'
                                                        size='small'
                                                        icon={ currentTreatedPlugin === pluginID ? 'loader' : false }
                                                        loading={ currentTreatedPlugin === pluginID }
                                                        text={ __( 'Install', 'sb-customizer' ) }
                                                        boxshadow={false}
                                                        onClick={ () => {
                                                            setCurrentTreatedPlugin( pluginID );
                                                            installPlugin( plugin.download_plugin )
                                                        } }
                                                    />
                                                }
                                                {
                                                    plugin.installed &&
                                                    <Button
                                                        type='secondary'
                                                        size='small'
                                                        icon='success'
                                                        text={ __( 'Installed', 'sb-customizer' ) }
                                                        disabled={true}
                                                        boxshadow={false}
                                                    />
                                                }
                                                {
                                                   ( plugin.installed && !plugin.activated ) &&
                                                    <Button
                                                        type='primary'
                                                        size='small'
                                                        text={ __( 'Activate', 'sb-customizer' ) }
                                                        icon={ currentTreatedPlugin === pluginID ? 'loader' : false }
                                                        loading={ currentTreatedPlugin === pluginID }
                                                        onClick={ () => {
                                                            setCurrentTreatedPlugin( pluginID );
                                                            activateDeactivatePlugin( plugin?.plugin, 'activate' )
                                                        } }
                                                    />
                                                }
                                                {
                                                   ( plugin.installed && plugin.activated ) &&
                                                    <Button
                                                        type='destructive'
                                                        size='small'
                                                        icon={ currentTreatedPlugin === pluginID ? 'loader' : false }
                                                        loading={ currentTreatedPlugin === pluginID }
                                                        text={ __( 'Deactivate', 'sb-customizer' ) }
                                                        onClick={ () => {
                                                            setCurrentTreatedPlugin( pluginID );
                                                            activateDeactivatePlugin( plugin?.plugin, 'deactivate' )
                                                        } }
                                                    />
                                                }
                                            </div>
                                        </div>
                                    </div>
                                )
                            } )
                        }
                    </section>

                </section>

            </section>
        </AboutusScreenContext.Provider>
    );
}

export default AboutPage;